<?php

namespace App\Services\UniversalScraper;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

class DomExtractor
{
    public function __construct(private readonly UrlTools $urls) {}

    /**
     * @param list<string>|null $keywords
     * @return list<string>
     */
    public function extractSectionLinks(string $html, string $baseUrl, ?array $keywords = null): array
    {
        $keywords ??= config('universal_scraper.defaults.nav_keywords', []);
        $keywords = array_map(fn ($value) => mb_strtolower((string) $value), $keywords);
        $links = [];

        foreach ($this->extractLinkItems($html, $baseUrl) as $item) {
            $target = mb_strtolower($item['href'] . ' ' . $item['text']);

            foreach ($keywords as $keyword) {
                if ($keyword !== '' && str_contains($target, $keyword)) {
                    if ($this->urls->isSameSite($baseUrl, $item['url'])) {
                        $links[$item['url']] = true;
                    }
                    break;
                }
            }
        }

        return array_keys($links);
    }

    /**
     * @return list<array{url: string, href: string, text: string}>
     */
    public function extractLinkItems(?string $html, string $baseUrl): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $xpath = $this->xpath($html);
        $nodes = $xpath->query('//a[@href]');
        $items = [];
        $seen = [];

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = trim($node->getAttribute('href'));

            if ($href === '' || preg_match('/^(javascript:|data:)/i', $href) === 1) {
                continue;
            }

            $url = $this->urls->absoluteUrl($baseUrl, $href);

            if (preg_match('/^(mailto:|tel:)/i', $url) === 1) {
                continue;
            }

            $text = $this->compactText($node->textContent ?? '');

            if ($text === '') {
                $text = $this->compactText($node->getAttribute('title') ?: $node->getAttribute('aria-label'));
            }

            $key = mb_strtolower($url . '|' . $text);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $items[] = ['url' => $url, 'href' => $href, 'text' => $text];
        }

        return $items;
    }

    /**
     * @return list<string>
     */
    public function extractTextLines(?string $html): array
    {
        if ($html === null || trim($html) === '') {
            return [];
        }

        $document = $this->document($html);
        $xpath = new DOMXPath($document);

        foreach (['script', 'style', 'noscript', 'svg', 'iframe', 'template'] as $tag) {
            $nodes = $document->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                $node?->parentNode?->removeChild($node);
            }
        }

        $lines = [];
        $seen = [];

        $this->collectLines($xpath, '//h1|//h2|//h3|//h4|//h5|//h6|//p|//li|//td|//th', 20, 500, $lines, $seen);

        if ($lines === []) {
            $this->collectLines($xpath, '//main|//article|//section', 40, 1200, $lines, $seen);
        }

        if ($lines !== []) {
            return $lines;
        }

        $flat = $this->compactText($document->textContent ?? '');

        return $flat !== '' ? [$flat] : [];
    }

    public function findNextPage(string $html, string $baseUrl, ?string $selector): ?string
    {
        $selector = trim((string) $selector);

        if ($selector === '') {
            return null;
        }

        $xpath = $this->xpath($html);
        $query = str_starts_with($selector, '//') ? $selector : $this->selectorToXpath($selector);

        if ($query === null) {
            return null;
        }

        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            $href = $node->getAttribute('href');

            if ($href !== '') {
                return $this->urls->absoluteUrl($baseUrl, $href);
            }
        }

        return null;
    }

    private function xpath(string $html): DOMXPath
    {
        return new DOMXPath($this->document($html));
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    /**
     * @param list<string> $lines
     * @param array<string, bool> $seen
     */
    private function collectLines(DOMXPath $xpath, string $query, int $minLen, int $maxLen, array &$lines, array &$seen): void
    {
        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }

            $text = $this->compactText($node->textContent ?? '');

            if (mb_strlen($text) < $minLen) {
                continue;
            }

            $parts = [$text];

            if (mb_strlen($text) > $maxLen) {
                $parts = preg_split('/(?<=[.;:!?])\s+/u', $text) ?: [];
            }

            foreach ($parts as $part) {
                $part = trim($part);

                if (mb_strlen($part) < $minLen) {
                    continue;
                }

                $key = mb_strtolower($part);

                if (isset($seen[$key])) {
                    continue;
                }

                $seen[$key] = true;
                $lines[] = $part;
            }
        }
    }

    private function compactText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function selectorToXpath(string $selector): ?string
    {
        try {
            return (new CssSelectorConverter)->toXPath($selector);
        } catch (Throwable) {
            //
        }

        if ($selector === 'a') {
            return '//a';
        }

        if (preg_match('/^#([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return "//*[@id='{$m[1]}']";
        }

        if (preg_match('/^\.([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\.([A-Za-z0-9_-]+)$/', $selector, $m) === 1) {
            return "//{$m[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }

        if (preg_match('/^([A-Za-z][A-Za-z0-9_-]*)\[([A-Za-z0-9_-]+)=["\']?([^"\']+)["\']?\]$/', $selector, $m) === 1) {
            return "//{$m[1]}[@{$m[2]}='{$m[3]}']";
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $selector) === 1) {
            return '//' . $selector;
        }

        return null;
    }
}
