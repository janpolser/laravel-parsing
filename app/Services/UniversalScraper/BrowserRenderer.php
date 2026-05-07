<?php

namespace App\Services\UniversalScraper;

use App\Services\UniversalScraper\Exceptions\ExternalRedirectException;
use RuntimeException;
use Symfony\Component\Process\Process;

class BrowserRenderer
{
    public function __construct(private readonly UrlTools $urls) {}

    public function render(string $url, ?float $timeoutSeconds = null): string
    {
        if (!config('universal_scraper.browser.enabled', true)) {
            throw new RuntimeException('Playwright renderer is disabled.');
        }

        $script = (string) config('universal_scraper.browser.renderer_script');

        if (!is_file($script)) {
            throw new RuntimeException("Playwright renderer script not found: {$script}");
        }

        $timeoutSeconds ??= (float) config('universal_scraper.browser.timeout_seconds', 25);
        $process = new Process([
            (string) config('universal_scraper.browser.node_binary', 'node'),
            $script,
            $url,
            (string) max(5, $timeoutSeconds),
        ], base_path());
        $process->setTimeout(max(10, $timeoutSeconds + 5));
        $process->run();

        if (!$process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            throw new RuntimeException($error !== '' ? $error : 'Playwright renderer failed.');
        }

        $payload = json_decode($process->getOutput(), true);

        if (!is_array($payload) || !is_string($payload['html'] ?? null)) {
            throw new RuntimeException('Playwright renderer returned invalid payload.');
        }

        $finalUrl = (string) ($payload['finalUrl'] ?? $url);

        if (!$this->urls->isSameSite($url, $finalUrl)) {
            throw new ExternalRedirectException($url, $finalUrl);
        }

        return $payload['html'];
    }
}
