<?php

namespace App\Services\UniversalScraper;

class PageContentPromptBuilder
{
    public function __construct(
        private readonly DomExtractor $dom,
        private readonly UrlTools $urls,
    ) {}

    public function build(string $prompt, string $url, string $html, ?string $baselineHtml = null): string
    {
        $siteRoot = $this->urls->siteRoot($url);
        $pageLines = $this->dom->extractTextLines($html);
        $baselineLines = $baselineHtml ? $this->dom->extractTextLines($baselineHtml) : [];
        $focusedLines = $this->removeSharedLines($pageLines, $baselineLines);
        $maxChars = max(3000, (int) config('universal_scraper.llm.source_max_chars', 60000));
        $textBudget = max(2000, (int) ($maxChars * 0.7));
        $linksBudget = max(1000, $maxChars - $textBudget);

        $focusedText = $this->truncateMiddle(implode("\n", $focusedLines), $textBudget);
        $pageLinks = $this->dom->extractLinkItems($html, $url);
        $baselineLinks = $baselineHtml ? $this->dom->extractLinkItems($baselineHtml, $siteRoot) : [];
        $baselineUrls = array_fill_keys(array_map(fn ($item) => mb_strtolower($item['url']), $baselineLinks), true);
        $filteredLinks = array_values(array_filter(
            $pageLinks,
            fn ($item) => !isset($baselineUrls[mb_strtolower($item['url'])]),
        ));
        $linksForPrompt = $filteredLinks !== [] ? $filteredLinks : $pageLinks;
        $linkLines = [];

        foreach ($linksForPrompt as $item) {
            $linkLines[] = '- ' . $item['url'] . ' | ' . mb_substr($item['text'] !== '' ? $item['text'] : '(no text)', 0, 220);
        }

        $linksBlock = $this->truncateMiddle($linkLines !== [] ? implode("\n", $linkLines) : '- (no links found)', $linksBudget);

        return implode("\n", [
            $prompt,
            '',
            "URL: {$url}",
            "SITE_ROOT: {$siteRoot}",
            'Below is the vacancy page content after removing text blocks shared with the homepage header/footer.',
            'All HTML tags are removed and important blocks are split by new lines.',
            'Use only this context and return JSON only by the schema rules above.',
            "TEXT:\n{$focusedText}",
            '',
            "LINKS_FROM_PAGE (url | description):\n{$linksBlock}",
        ]);
    }

    /**
     * @param list<string> $pageLines
     * @param list<string> $baselineLines
     * @return list<string>
     */
    private function removeSharedLines(array $pageLines, array $baselineLines): array
    {
        if ($pageLines === [] || $baselineLines === []) {
            return $pageLines;
        }

        $baseline = array_fill_keys(array_map('mb_strtolower', $baselineLines), true);
        $filtered = [];

        foreach ($pageLines as $line) {
            if (isset($baseline[mb_strtolower($line)])) {
                continue;
            }

            $filtered[] = $line;
        }

        return $filtered !== [] ? $filtered : $pageLines;
    }

    private function truncateMiddle(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $head = (int) ($maxChars * 0.7);
        $tail = $maxChars - $head;

        return mb_substr($text, 0, $head) . "\n...[truncated]...\n" . mb_substr($text, -$tail);
    }
}
