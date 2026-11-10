<?php

namespace App\Services\UniversalScraper;

use App\Services\UniversalScraper\Exceptions\ExternalRedirectException;
use App\Services\UniversalScraper\Exceptions\PageFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class HtmlFetcher
{
    public function __construct(private readonly UrlTools $urls) {}

    public function fetch(string $url, ?float $timeoutSeconds = null): string
    {
        $timeoutSeconds ??= (float) config('universal_scraper.fetch.timeout_seconds', 15);
        $attempts = max(1, (int) config('universal_scraper.fetch.retries', 3));
        $sleepMs = max(0, (int) config('universal_scraper.fetch.retry_sleep_ms', 2000));
        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->fetchOnce($url, $timeoutSeconds);
            } catch (Throwable $exception) {
                $last = $exception;

                if (!$this->shouldRetry($exception) || $attempt === $attempts) {
                    break;
                }

                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
        }

        if ($last instanceof PageFetchException || $last instanceof ExternalRedirectException) {
            throw $last;
        }

        throw new PageFetchException("Could not fetch {$url}: " . ($last?->getMessage() ?? 'unknown error'), null, $last);
    }

    private function fetchOnce(string $url, float $timeoutSeconds): string
    {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->withOptions(['allow_redirects' => true])
                ->withHeaders(['User-Agent' => (string) config('universal_scraper.fetch.user_agent', 'jobscraper/laravel')])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new PageFetchException("Connection error while fetching {$url}: {$exception->getMessage()}", null, $exception);
        }

        $this->throwForBadStatus($url, $response);
        $this->ensureSameSite($url, $response);

        $html = $response->body();
        $challenge = $this->extractJsCookieChallenge($html);

        if ($challenge === null) {
            return $html;
        }

        [$cookieName, $cookieValue, $location] = $challenge;
        $targetUrl = $this->urls->absoluteUrl((string) $response->effectiveUri(), $location);
        $host = (string) parse_url($targetUrl, PHP_URL_HOST);

        $secondResponse = Http::timeout($timeoutSeconds)
            ->withOptions(['allow_redirects' => true])
            ->withHeaders(['User-Agent' => (string) config('universal_scraper.fetch.user_agent', 'jobscraper/laravel')])
            ->withCookies([$cookieName => $cookieValue], $host)
            ->get($targetUrl);

        $this->throwForBadStatus($targetUrl, $secondResponse);
        $this->ensureSameSite($url, $secondResponse);

        return $secondResponse->body();
    }

    private function throwForBadStatus(string $url, Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new PageFetchException("HTTP {$response->status()} while fetching {$url}", $response->status());
    }

    private function ensureSameSite(string $sourceUrl, Response $response): void
    {
        $finalUrl = (string) $response->effectiveUri();

        if ($finalUrl !== '' && !$this->urls->isSameSite($sourceUrl, $finalUrl)) {
            throw new ExternalRedirectException($sourceUrl, $finalUrl);
        }
    }

    /**
     * @return array{string, string, string}|null
     */
    private function extractJsCookieChallenge(string $html): ?array
    {
        if (
            preg_match('/document\.cookie\s*=\s*"([^"]+)"/i', $html, $cookieMatch) !== 1
            || preg_match('/document\.location\.href\s*=\s*"([^"]+)"/i', $html, $locationMatch) !== 1
        ) {
            return null;
        }

        $cookie = trim($cookieMatch[1]);
        $location = trim($locationMatch[1]);
        $cookiePair = trim(explode(';', $cookie, 2)[0] ?? '');

        if ($cookiePair === '' || !str_contains($cookiePair, '=') || $location === '') {
            return null;
        }

        [$name, $value] = explode('=', $cookiePair, 2);

        return [trim($name), trim($value), $location];
    }

    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ExternalRedirectException) {
            return false;
        }

        if ($exception instanceof PageFetchException) {
            return $exception->statusCode === null || $exception->statusCode >= 500;
        }

        return true;
    }
}
