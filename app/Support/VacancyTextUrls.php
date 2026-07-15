<?php

namespace App\Support;

class VacancyTextUrls
{
    private const URL_PATTERN = '~(?<![\w@])(?:https?://|www\.)[^\s<>"\']*[^\s<>"\'.,;:!?()\[\]{}]~iu';

    private const TOP_LEVEL_TEXT_FIELDS = ['description', 'duty'];

    private const NESTED_TEXT_FIELDS = [
        'term' => ['text'],
        'requirement' => ['age', 'sex', 'education', 'experience', 'qualification'],
    ];

    public static function extractFromVacancy(array $vacancy): array
    {
        $urls = self::normalizeUrls($vacancy['text_urls'] ?? $vacancy['text-urls'] ?? []);

        foreach (self::TOP_LEVEL_TEXT_FIELDS as $field) {
            if (!array_key_exists($field, $vacancy) || $vacancy[$field] === null || $vacancy[$field] === '') {
                continue;
            }

            [$text, $fieldUrls] = self::extractFromText((string) $vacancy[$field]);
            $vacancy[$field] = $text;
            $urls = array_merge($urls, $fieldUrls);
        }

        foreach (self::NESTED_TEXT_FIELDS as $group => $fields) {
            if (empty($vacancy[$group]) || !is_array($vacancy[$group])) {
                continue;
            }

            foreach ($fields as $field) {
                if (
                    !array_key_exists($field, $vacancy[$group])
                    || $vacancy[$group][$field] === null
                    || $vacancy[$group][$field] === ''
                    || !is_scalar($vacancy[$group][$field])
                ) {
                    continue;
                }

                [$text, $fieldUrls] = self::extractFromText((string) $vacancy[$group][$field]);
                $vacancy[$group][$field] = $text;
                $urls = array_merge($urls, $fieldUrls);
            }
        }

        $urls = array_values(array_unique(array_filter($urls, static fn ($url) => $url !== '')));

        unset($vacancy['text-urls']);

        if ($urls !== []) {
            $vacancy['text_urls'] = $urls;
        } else {
            unset($vacancy['text_urls']);
        }

        return $vacancy;
    }

    private static function extractFromText(string $text): array
    {
        $urls = [];

        $withoutUrls = preg_replace_callback(
            self::URL_PATTERN,
            static function (array $matches) use (&$urls): string {
                $urls[] = $matches[0];

                return '';
            },
            $text
        ) ?? $text;

        return [self::normalizeText($withoutUrls), $urls];
    }

    private static function normalizeUrls(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (!is_array($value)) {
            $value = [$value];
        }

        $urls = [];
        foreach ($value as $url) {
            if (!is_scalar($url)) {
                continue;
            }

            $url = trim((string) $url);
            if ($url !== '') {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function normalizeText(string $text): string
    {
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t]*\R[ \t]*/u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = preg_replace('/\s+([.,;:!?])/u', '$1', $text) ?? $text;
        $text = preg_replace('/\s*[-:]\s*([.,;:!?])/u', '$1', $text) ?? $text;

        return trim($text);
    }
}
