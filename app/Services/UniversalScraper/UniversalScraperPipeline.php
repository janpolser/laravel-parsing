<?php

namespace App\Services\UniversalScraper;

use App\Models\ScraperSite;
use App\Services\UniversalScraper\Exceptions\ExternalRedirectException;
use App\Services\UniversalScraper\Exceptions\PageFetchException;
use Throwable;

class UniversalScraperPipeline
{
    public function __construct(
        private readonly HtmlFetcher $fetcher,
        private readonly BrowserRenderer $browser,
        private readonly DomExtractor $dom,
        private readonly PageContentPromptBuilder $promptBuilder,
        private readonly LlmJsonClient $llm,
        private readonly VacancyNormalizer $normalizer,
        private readonly ContactNormalizer $contacts,
        private readonly UrlTools $urls,
    ) {}

    /**
     * @return array{jobs: list<array<string, mixed>>, pages_scanned: int}
     */
    public function scrape(ScraperSite $site, ?string $llmDevice = null): array
    {
        $config = $this->siteConfig($site);
        $baselineHtml = null;
        $htmlCache = [];
        $pagesScanned = 0;
        $jobs = [];

        if (empty($config['start_urls'])) {
            return ['jobs' => [], 'pages_scanned' => 0];
        }

        $mainUrl = $this->urls->siteRoot($config['start_urls'][0]);

        try {
            $baselineHtml = $this->getHtmlCached($mainUrl, $htmlCache);
        } catch (Throwable) {
            $baselineHtml = null;
        }

        $companyContacts = $this->contacts->merge($config['company_contacts'] ?? []);

        if ($baselineHtml) {
            $companyContacts = $this->contacts->merge([...$companyContacts, ...$this->contacts->extractFromText($baselineHtml)]);
        }

        foreach ($config['start_urls'] as $startUrl) {
            try {
                $initialHtml = $this->getHtmlCached($startUrl, $htmlCache);
            } catch (Throwable $exception) {
                if ($this->isSkippablePageError($exception)) {
                    continue;
                }
                throw $exception;
            }

            $sectionLinks = $this->dom->extractSectionLinks($initialHtml, $startUrl, $config['nav_keywords'] ?? null);
            $pagesToVisit = $sectionLinks !== [] ? $sectionLinks : [$startUrl];

            foreach ($pagesToVisit as $pageUrl) {
                $currentUrl = $pageUrl;
                $pageIndex = 0;

                while ($currentUrl && $pageIndex < (int) $config['max_pages']) {
                    $pageIndex++;
                    $pagesScanned++;

                    try {
                        $html = $this->getHtmlCached($currentUrl, $htmlCache);
                    } catch (Throwable $exception) {
                        if ($this->isSkippablePageError($exception)) {
                            break;
                        }
                        throw $exception;
                    }

                    $inlineJobs = $this->extractJobsInPage(
                        $currentUrl,
                        $config,
                        $companyContacts,
                        $baselineHtml,
                        $html,
                        $llmDevice,
                    );

                    if ($inlineJobs !== []) {
                        array_push($jobs, ...$inlineJobs);
                    }

                    $currentUrl = $this->dom->findNextPage($html, $currentUrl, $config['pagination_selector'] ?? null);
                    $throttle = (float) ($config['throttle_seconds'] ?? 0);

                    if ($throttle > 0) {
                        usleep((int) ($throttle * 1000000));
                    }
                }
            }
        }

        return ['jobs' => $jobs, 'pages_scanned' => $pagesScanned];
    }

    /**
     * @return array<string, mixed>
     */
    public function siteConfig(ScraperSite $site): array
    {
        $config = config('universal_scraper.defaults', []);
        $baseUrl = $site->base_url;
        $careerUrl = $site->career_url;
        $startUrl = $baseUrl;

        if ($baseUrl && $careerUrl) {
            $startUrl = $this->urls->absoluteUrl($baseUrl, $careerUrl);
        } elseif ($careerUrl) {
            $startUrl = $careerUrl;
        }

        if ($startUrl) {
            $config['start_urls'] = [$startUrl];
        }

        $config['company_name'] = $site->company_name;
        $config['region'] = $site->region;
        $config['city'] = $site->city;
        $config['address'] = $site->address;
        $rawContacts = [];
        $rawContacts = array_merge($rawContacts, $site->company_emails ?: []);
        $rawContacts = array_merge($rawContacts, $site->company_phones ?: []);
        $rawContacts = array_merge($rawContacts, $site->company_sites ?: []);

        if ($site->company_contact) {
            $rawContacts[] = $site->company_contact;
        }

        if ($site->base_url) {
            $rawContacts[] = $site->base_url;
        }

        $config['company_contacts'] = $this->contacts->merge($rawContacts);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string> $companyContacts
     * @return list<array<string, mixed>>
     */
    private function extractJobsInPage(
        string $pageUrl,
        array $config,
        array $companyContacts,
        ?string $baselineHtml,
        string $pageHtml,
        ?string $llmDevice,
    ): array {
        $prompt = $this->ensurePagePrompt((string) ($config['page_prompt'] ?? $config['prompt']));
        $needsJs = (bool) ($config['needs_js'] ?? false);
        $jsFallback = (bool) ($config['js_fallback'] ?? false);
        $attempts = [$needsJs];

        if ($jsFallback !== $needsJs) {
            $attempts[] = $jsFallback;
        }

        foreach ($attempts as $attemptJs) {
            $html = $pageHtml;

            if ($attemptJs) {
                try {
                    $html = $this->browser->render($pageUrl, (float) config('universal_scraper.browser.timeout_seconds', 25));
                } catch (Throwable) {
                    continue;
                }
            }

            foreach ($this->llmConfigs($llmDevice) as $llmConfig) {
                try {
                    $llmPrompt = $this->promptBuilder->build($prompt, $pageUrl, $html, $baselineHtml);
                    $rawJobs = $this->normalizeLlmJobs($this->llm->chatJson($llmPrompt, $llmConfig));
                    $jobs = [];

                    foreach ($rawJobs as $rawJob) {
                        $job = $this->normalizer->normalize(
                            $rawJob,
                            $config['required_fields'] ?? ['title', 'company', 'description', 'contacts'],
                            $config['company_name'] ?? null,
                            $this->defaultLocation($config),
                            $companyContacts,
                            $pageUrl,
                        );

                        if ($job !== null) {
                            $jobs[] = $job;
                        }
                    }

                    if ($jobs !== []) {
                        return $jobs;
                    }
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, string> $cache
     */
    private function getHtmlCached(string $url, array &$cache): string
    {
        if (!array_key_exists($url, $cache)) {
            $cache[$url] = $this->fetcher->fetch($url);
        }

        return $cache[$url];
    }

    private function ensurePagePrompt(string $prompt): string
    {
        if (str_contains(mb_strtolower($prompt), 'верни строго []')) {
            return $prompt;
        }

        return $prompt . ' Верни только JSON-массив. Если вакансий нет, верни строго [] без текста и markdown.';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function defaultLocation(array $config): ?string
    {
        $parts = array_filter(array_map(
            fn ($value) => trim((string) $value),
            [$config['city'] ?? null, $config['region'] ?? null, $config['address'] ?? null],
        ));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeLlmJobs(mixed $result): array
    {
        if (!is_array($result)) {
            return [];
        }

        if (array_is_list($result)) {
            return array_values(array_filter($result, 'is_array'));
        }

        if (isset($result['content']) && is_array($result['content'])) {
            return array_values(array_filter($result['content'], 'is_array'));
        }

        return [$result];
    }

    private function isSkippablePageError(Throwable $exception): bool
    {
        return $exception instanceof ExternalRedirectException
            || ($exception instanceof PageFetchException && in_array($exception->statusCode, [403, 404], true));
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
}
