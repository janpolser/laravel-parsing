<?php

namespace App\Services\UniversalScraper;

use App\Models\ScraperSite;

class SiteImporter
{
    public function __construct(
        private readonly UrlTools $urls,
        private readonly ContactNormalizer $contacts,
    ) {}

    /**
     * @param iterable<mixed> $entries
     */
    public function import(iterable $entries, bool $overwriteLocation = false): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $normalized = $this->normalizeEntry($entry);

            if ($normalized === null) {
                continue;
            }

            [$rawUrl, $company, $region, $city, $address, $companyContacts] = $normalized;
            $url = $this->urls->normalizeImportUrl($rawUrl);

            if ($url === null) {
                continue;
            }

            $siteKey = $this->urls->siteKeyFromUrl($url);
            $mergedContacts = $this->contacts->merge([$url, ...$companyContacts]);
            $split = $this->contacts->splitKinds($mergedContacts);
            $companyContact = $split['emails'][0] ?? $split['phones'][0] ?? $split['sites'][0] ?? $mergedContacts[0] ?? null;

            /** @var ScraperSite $site */
            $site = ScraperSite::query()->firstOrNew(['site_key' => $siteKey]);
            $site->base_url = $site->base_url ?: $url;
            $site->company_name = $site->company_name ?: $this->cleanCompanyName($company);
            $site->company_contact = $overwriteLocation ? ($companyContact ?: $site->company_contact) : ($site->company_contact ?: $companyContact);
            $site->company_emails = $overwriteLocation || empty($site->company_emails) ? $split['emails'] : $site->company_emails;
            $site->company_phones = $overwriteLocation || empty($site->company_phones) ? $split['phones'] : $site->company_phones;
            $site->company_sites = $overwriteLocation || empty($site->company_sites) ? $split['sites'] : $site->company_sites;
            $site->region = $overwriteLocation ? ($region ?: $site->region) : ($site->region ?: $region);
            $site->city = $overwriteLocation ? ($city ?: $site->city) : ($site->city ?: $city);
            $site->address = $overwriteLocation ? ($address ?: $site->address) : ($site->address ?: $address);
            $site->save();
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public function readTxt(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map('trim', $lines)));
    }

    /**
     * @return list<string>
     */
    public function readCsv(string $path, string $urlColumn): array
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            return [];
        }

        $header = fgetcsv($handle);
        $entries = [];

        if (!is_array($header)) {
            fclose($handle);

            return [];
        }

        while (($row = fgetcsv($handle)) !== false) {
            $assoc = array_combine($header, $row);
            if (is_array($assoc)) {
                $entries[] = (string) ($assoc[$urlColumn] ?? '');
            }
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @return list<string>
     */
    public function readJsonLines(string $path, string $urlField): array
    {
        $entries = [];
        $handle = fopen($path, 'rb');

        if (!$handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $row = json_decode($line, true);

            if (is_array($row)) {
                $entries[] = (string) ($row[$urlField] ?? '');
            }
        }

        fclose($handle);

        return $entries;
    }

    /**
     * @return array{string, ?string, ?string, ?string, ?string, list<string>}|null
     */
    private function normalizeEntry(mixed $entry): ?array
    {
        if (is_string($entry)) {
            return [$entry, null, null, null, null, []];
        }

        if (!is_array($entry)) {
            return null;
        }

        if (count($entry) >= 6) {
            return [
                (string) $entry[0],
                $this->cleanText($entry[1] ?? null),
                $this->cleanText($entry[2] ?? null),
                $this->cleanText($entry[3] ?? null),
                $this->cleanText($entry[4] ?? null),
                $this->contacts->splitRaw($entry[5] ?? null),
            ];
        }

        if (count($entry) >= 5) {
            return [
                (string) $entry[0],
                $this->cleanText($entry[1] ?? null),
                $this->cleanText($entry[2] ?? null),
                $this->cleanText($entry[3] ?? null),
                $this->cleanText($entry[4] ?? null),
                [],
            ];
        }

        if (count($entry) >= 3) {
            [$region, $city, $address] = $this->splitLocation($this->cleanText($entry[2] ?? null));

            return [(string) $entry[0], $this->cleanText($entry[1] ?? null), $region, $city, $address, []];
        }

        if (count($entry) >= 2) {
            return [(string) $entry[0], $this->cleanText($entry[1] ?? null), null, null, null, []];
        }

        return null;
    }

    /**
     * @return array{?string, ?string, ?string}
     */
    private function splitLocation(?string $location): array
    {
        if ($location === null || $location === '') {
            return [null, null, null];
        }

        $parts = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $location) ?: [])));

        return match (count($parts)) {
            0 => [null, null, null],
            1 => [null, null, $parts[0]],
            2 => [$parts[0], null, $parts[1]],
            default => [$parts[0], $parts[1], implode(', ', array_slice($parts, 2))],
        };
    }

    private function cleanCompanyName(?string $name): ?string
    {
        $name = $this->cleanText($name);

        if ($name === null) {
            return null;
        }

        foreach ([',', ';'] as $separator) {
            if (str_contains($name, $separator)) {
                $name = trim(explode($separator, $name, 2)[0]);
            }
        }

        return $name !== '' ? $name : null;
    }

    private function cleanText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value, " \t\n\r\0\x0B'\"");
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return $text !== '' ? $text : null;
    }
}
