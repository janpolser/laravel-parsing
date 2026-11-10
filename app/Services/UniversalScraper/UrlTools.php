<?php

namespace App\Services\UniversalScraper;

class UrlTools
{
    public function normalizeHost(string $url): string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST)));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public function isSameSite(string $baseUrl, string $candidateUrl): bool
    {
        $baseHost = $this->normalizeHost($baseUrl);
        $candidateHost = $this->normalizeHost($candidateUrl);

        if ($baseHost === '' || $candidateHost === '') {
            return false;
        }

        return $candidateHost === $baseHost
            || str_ends_with($candidateHost, '.' . $baseHost)
            || str_ends_with($baseHost, '.' . $candidateHost);
    }

    public function absoluteUrl(string $baseUrl, string $href): string
    {
        $href = trim($href);

        if ($href === '') {
            return $baseUrl;
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'http';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $root = $scheme . '://' . $host . $port;

        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }

        if (str_starts_with($href, '/')) {
            return $root . $this->normalizePath($href);
        }

        $path = $base['path'] ?? '/';
        $directory = preg_replace('~/[^/]*$~', '/', $path) ?: '/';

        return $root . $this->normalizePath($directory . $href);
    }

    public function siteRoot(string $url): string
    {
        $parts = parse_url($url);

        if (empty($parts['scheme']) || empty($parts['host'])) {
            return $url;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $parts['scheme'] . '://' . $parts['host'] . $port . '/';
    }

    public function normalizeImportUrl(string $url): ?string
    {
        $url = $this->cleanRawUrl($url);

        if ($url === '') {
            return null;
        }

        if (!preg_match('~^https?://~i', $url)) {
            $url = 'http://' . $url;
        }

        $parts = parse_url($url);

        if (empty($parts['host'])) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'http';
        $path = $parts['path'] ?? '/';

        return $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($path ?: '/');
    }

    public function siteKeyFromUrl(string $url): string
    {
        $host = $this->normalizeHost($url);

        if ($host === '') {
            return '';
        }

        $labels = array_values(array_filter(explode('.', $host)));

        if (count($labels) <= 2) {
            return $host;
        }

        $lastTwo = implode('.', array_slice($labels, -2));
        $lastThree = implode('.', array_slice($labels, -3));
        $compoundSuffixes = [
            'com.ru',
            'net.ru',
            'org.ru',
            'pp.ru',
            'co.uk',
            'org.uk',
            'ac.uk',
            'com.au',
            'net.au',
            'com.br',
        ];

        if (in_array($lastTwo, $compoundSuffixes, true)) {
            return $lastThree;
        }

        return $lastTwo;
    }

    public function careerPathOnly(?string $careerUrl): ?string
    {
        if ($careerUrl === null || trim($careerUrl) === '') {
            return null;
        }

        $path = trim((string) parse_url($careerUrl, PHP_URL_PATH));

        return $path !== '' && $path !== '/' ? $path : null;
    }

    private function cleanRawUrl(string $url): string
    {
        $url = trim(str_replace('\\', ' ', $url), " \t\n\r\0\x0B'\"");

        if (str_contains($url, ',')) {
            $url = explode(',', $url, 2)[0];
        }

        if (str_contains($url, ' ')) {
            $url = preg_split('/\s+/', $url, 2)[0] ?? '';
        }

        return $url;
    }

    private function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
