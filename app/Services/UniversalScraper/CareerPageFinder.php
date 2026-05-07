<?php

namespace App\Services\UniversalScraper;

use Throwable;

class CareerPageFinder
{
    public function __construct(
        private readonly HtmlFetcher $fetcher,
        private readonly BrowserRenderer $browser,
        private readonly DomExtractor $dom,
        private readonly LlmJsonClient $llm,
        private readonly UrlTools $urls,
    ) {}

    /**
     * @return array{url: ?string, too_big: bool}
     */
    public function find(string $startUrl, bool $useLlm = true, ?string $llmDevice = null): array
    {
        $html = $this->fetcher->fetch($startUrl);
        $maxBytes = max(100000, (int) config('universal_scraper.career_finder.max_html_bytes', 8000000));

        if (strlen($html) > $maxBytes) {
            return ['url' => null, 'too_big' => true];
        }

        $links = $this->detectCareerLinks($html, $startUrl);

        if ($links !== []) {
            return ['url' => $links[0], 'too_big' => false];
        }

        if (config('universal_scraper.career_finder.playwright_fallback', true)) {
            try {
                $renderedHtml = $this->browser->render($startUrl, (float) config('universal_scraper.browser.timeout_seconds', 25));

                if (strlen($renderedHtml) > $maxBytes) {
                    return ['url' => null, 'too_big' => true];
                }

                $links = $this->detectCareerLinks($renderedHtml, $startUrl);

                if ($links !== []) {
                    return ['url' => $links[0], 'too_big' => false];
                }

                $html = $renderedHtml;
            } catch (Throwable) {
                // Plain HTML and LLM fallback are still useful when browser rendering fails.
            }
        }

        if (!$useLlm || !config('universal_scraper.career_finder.llm_enabled', true)) {
            return ['url' => null, 'too_big' => false];
        }

        $guess = $this->guessWithLlm($html, $startUrl, $llmDevice);

        return ['url' => $guess, 'too_big' => false];
    }

    /**
     * @return list<string>
     */
    private function detectCareerLinks(string $html, string $baseUrl): array
    {
        $keywords = config('universal_scraper.defaults.nav_keywords', []);
        $patterns = config('universal_scraper.defaults.url_patterns', []);
        $links = [];

        foreach ($this->dom->extractLinkItems($html, $baseUrl) as $item) {
            $target = mb_strtolower($item['href'] . ' ' . $item['text']);
            $href = mb_strtolower($item['href']);
            $matched = false;

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($target, mb_strtolower((string) $keyword))) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                foreach ($patterns as $pattern) {
                    if ($pattern !== '' && str_contains($href, mb_strtolower((string) $pattern))) {
                        $matched = true;
                        break;
                    }
                }
            }

            if ($matched && $this->urls->isSameSite($baseUrl, $item['url'])) {
                $links[$item['url']] = true;
            }
        }

        return array_keys($links);
    }

    private function guessWithLlm(string $html, string $baseUrl, ?string $llmDevice): ?string
    {
        [$prompt, $linkCount] = $this->buildPrompt($baseUrl, $html);

        if ($linkCount === 0) {
            return null;
        }

        $configs = $this->llmConfigs($llmDevice);

        foreach ($configs as $config) {
            try {
                $result = $this->llm->chatJson($prompt, $config, 96);
                $candidate = $this->extractCareerUrl($result);
                $normalized = $this->normalizeCareerUrl($candidate, $baseUrl);

                if ($normalized !== null) {
                    return $normalized;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @return array{string, int}
     */
    private function buildPrompt(string $baseUrl, string $html): array
    {
        $items = $this->dom->extractLinkItems($html, $baseUrl);
        $baseHost = (string) parse_url($baseUrl, PHP_URL_HOST);
        $keywords = config('universal_scraper.defaults.nav_keywords', []);
        $patterns = config('universal_scraper.defaults.url_patterns', []);
        $scored = [];
        $seen = [];

        foreach ($items as $index => $item) {
            $url = $item['url'];
            $parts = parse_url($url);

            if (!in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
                continue;
            }

            if ($baseHost && $parts['host'] !== $baseHost) {
                continue;
            }

            $path = $parts['path'] ?? '/';
            $cleanUrl = ($parts['scheme'] ?? 'http') . '://' . $parts['host'] . $path;

            if (isset($seen[$cleanUrl])) {
                continue;
            }

            $seen[$cleanUrl] = true;
            $target = mb_strtolower($item['href'] . ' ' . $item['text']);
            $score = 0;

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($target, mb_strtolower((string) $keyword))) {
                    $score += 4;
                    break;
                }
            }

            foreach ($patterns as $pattern) {
                if ($pattern !== '' && str_contains(mb_strtolower($item['href']), mb_strtolower((string) $pattern))) {
                    $score += 5;
                    break;
                }
            }

            if (substr_count($path, '/') <= 2) {
                $score += 1;
            }

            $text = mb_substr($item['text'], 0, max(30, (int) config('universal_scraper.career_finder.link_text_max_chars', 140)));
            $scored[] = ['score' => $score, 'index' => $index, 'line' => "- href: {$path}; text: {$text}"];
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index']);

        $maxLinks = max(10, (int) config('universal_scraper.career_finder.llm_max_links', 180));
        $tailLinks = max(0, (int) config('universal_scraper.career_finder.llm_tail_links', 40));
        $selected = array_slice($scored, 0, max(0, $maxLinks - $tailLinks));
        $tail = array_slice($scored, -$tailLinks);
        $lines = [];

        foreach ([...$selected, ...$tail] as $item) {
            $lines[$item['line']] = true;

            if (count($lines) >= $maxLinks) {
                break;
            }
        }

        $linksBlock = $lines !== [] ? implode("\n", array_keys($lines)) : '- (no internal links extracted)';
        $prompt = implode("\n", [
            'Ты определяешь ссылку на раздел вакансий/карьеры.',
            'У тебя есть URL главной страницы и сокращенный список внутренних ссылок с нее.',
            'Ответь строго JSON-объектом без markdown в формате: {"career_url":"<absolute_or_relative_url_or_empty_string>"}.',
            "Если подходящей ссылки нет, верни {\"career_url\":\"\"}.",
            '',
            "base_url: {$baseUrl}",
            "links:\n{$linksBlock}",
        ]);

        return [$prompt, count($lines)];
    }

    private function extractCareerUrl(mixed $result): ?string
    {
        if (is_array($result)) {
            if (is_string($result['career_url'] ?? null)) {
                return trim($result['career_url']);
            }

            foreach ($result as $item) {
                $value = $this->extractCareerUrl($item);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return is_string($result) ? trim($result) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function llmConfigs(?string $llmDevice): array
    {
        $configs = [];

        try {
            $configs[] = $this->llm->primaryConfig($llmDevice);
        } catch (Throwable) {
            //
        }

        $fallback = $this->llm->fallbackConfig();

        if ($fallback !== null) {
            $configs[] = $fallback;
        }

        return $configs;
    }

    private function normalizeCareerUrl(?string $candidate, string $baseUrl): ?string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '' || str_contains($candidate, '{') || str_contains($candidate, "\n")) {
            return null;
        }

        if (preg_match('/^(mailto:|tel:|javascript:)/i', $candidate) === 1) {
            return null;
        }

        $full = $this->urls->absoluteUrl($baseUrl, $candidate);
        $scheme = parse_url($full, PHP_URL_SCHEME);
        $host = parse_url($full, PHP_URL_HOST);

        return in_array($scheme, ['http', 'https'], true) && $host ? $full : null;
    }
}
