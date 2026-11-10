<?php

namespace App\Services\UniversalScraper;

use Illuminate\Support\Carbon;

class VacancyNormalizer
{
    public function __construct(private readonly ContactNormalizer $contacts) {}

    /**
     * @param array<string, mixed> $raw
     * @param list<string> $requiredFields
     * @param list<string> $companyContacts
     * @return array<string, mixed>|null
     */
    public function normalize(
        array $raw,
        array $requiredFields,
        ?string $companyNameDefault,
        ?string $locationDefault,
        array $companyContacts,
        string $pageUrl,
        ?string $sourceUrlFallback = null,
    ): ?array {
        $item = $raw;
        $rawContacts = $item['contacts'] ?? [];
        $parsedContacts = $this->normalizeRawContacts($rawContacts);
        $mergedContacts = $this->contacts->merge([...$parsedContacts, ...$companyContacts]);

        if ($mergedContacts !== []) {
            $item['contacts'] = $mergedContacts;
        }

        if ($companyNameDefault && empty($item['company'])) {
            $item['company'] = $companyNameDefault;
        }

        if ($locationDefault && empty($item['location'])) {
            $item['location'] = $locationDefault;
        }

        $fallbackSource = $sourceUrlFallback ?: $pageUrl;

        if (empty($item['contacts'])) {
            $item['contacts'] = $this->contacts->merge([...$companyContacts, $fallbackSource]);
        }

        if (empty($item['source_url'])) {
            $item['source_url'] = $fallbackSource;
        }

        $salary = is_array($item['salary'] ?? null) ? $item['salary'] : [];
        $normalized = [
            'title' => $this->stringOrEmpty($item['title'] ?? null),
            'company' => $this->nullableString($item['company'] ?? null),
            'location' => $this->nullableString($item['location'] ?? null),
            'description' => $this->nullableString($item['description'] ?? null),
            'contacts' => array_values(array_filter((array) ($item['contacts'] ?? []), 'is_string')),
            'salary_value' => $this->nullableFloat($salary['value'] ?? null),
            'salary_currency' => $this->nullableString($salary['currency'] ?? null),
            'job_type' => $this->nullableString($item['type'] ?? $item['job_type'] ?? null),
            'level' => $this->nullableString($item['level'] ?? null),
            'skills' => $this->stringList($item['skills'] ?? []),
            'posted_at' => $this->nullableString($item['posted_at'] ?? null),
            'source_url' => $this->nullableString($item['source_url'] ?? null),
            'scraped_at' => Carbon::now(),
        ];

        foreach ($requiredFields as $field) {
            $key = $field === 'type' ? 'job_type' : $field;
            $value = $normalized[$key] ?? null;

            if (!$this->hasValue($value)) {
                return null;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function normalizeRawContacts(mixed $rawContacts): array
    {
        if (is_string($rawContacts)) {
            $rawContacts = [$rawContacts];
        }

        if (!is_array($rawContacts)) {
            return [];
        }

        $values = [];

        foreach ($rawContacts as $entry) {
            if (is_string($entry)) {
                $values[] = $entry;
                continue;
            }

            if (is_array($entry)) {
                foreach (['phone', 'email', 'url', 'contact', 'value'] as $key) {
                    if (is_string($entry[$key] ?? null)) {
                        $values[] = $entry[$key];
                    }
                }
            }
        }

        return $this->contacts->merge($values);
    }

    private function stringOrEmpty(mixed $value): string
    {
        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn ($item) => is_scalar($item) ? trim((string) $item) : '',
            $value,
        )));
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
