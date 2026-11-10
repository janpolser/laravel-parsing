<?php

namespace App\Services\UniversalScraper;

class ContactNormalizer
{
    private const EMAIL_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    private const PHONE_PATTERN = '/\+?\d[\d\s().-]{7,}\d/';

    public function normalize(?string $value): ?string
    {
        $contact = trim((string) $value);

        if ($contact === '' || mb_strlen($contact) > 120) {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $contact) === 1) {
            return mb_strtolower($contact);
        }

        if (preg_match('/^(?:https?:\/\/|mailto:|tel:)/i', $contact) === 1) {
            return $contact;
        }

        $contact = trim((string) preg_replace('/^(?:tel:|phone:)\s*/i', '', $contact));

        if ($contact === '' || str_contains($contact, '.')) {
            return null;
        }

        if (preg_match('/^[+\d\s().-]+$/', $contact) !== 1) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $contact) ?? '';

        if (strlen($digits) < 6 || strlen($digits) > 15) {
            return null;
        }

        if (preg_match('/^\+?0\d{3}-0\d{3}$/', $contact) === 1) {
            return null;
        }

        return $contact;
    }

    /**
     * @return list<string>
     */
    public function extractFromText(string $text): array
    {
        $rawContacts = [];

        if (preg_match_all(self::EMAIL_PATTERN, $text, $emails) > 0) {
            $rawContacts = array_merge($rawContacts, $emails[0]);
        }

        if (preg_match_all(self::PHONE_PATTERN, $text, $phones) > 0) {
            $rawContacts = array_merge($rawContacts, $phones[0]);
        }

        return $this->merge($rawContacts);
    }

    /**
     * @param iterable<string|null> $values
     * @return list<string>
     */
    public function merge(iterable $values): array
    {
        $seen = [];
        $result = [];

        foreach ($values as $value) {
            $normalized = $this->normalize($value);

            if ($normalized === null || isset($seen[$normalized])) {
                continue;
            }

            $seen[$normalized] = true;
            $result[] = $normalized;
        }

        return $result;
    }

    /**
     * @param iterable<string|null> $values
     * @return array{emails: list<string>, phones: list<string>, sites: list<string>}
     */
    public function splitKinds(iterable $values): array
    {
        $emails = [];
        $phones = [];
        $sites = [];

        foreach ($values as $value) {
            $normalized = $this->normalize($value);

            if ($normalized === null) {
                continue;
            }

            $lower = mb_strtolower($normalized);

            if (str_starts_with($lower, 'mailto:')) {
                $email = $this->normalize(substr($normalized, 7));
                if ($email !== null && str_contains($email, '@')) {
                    $emails[] = $email;
                }
                continue;
            }

            if (str_starts_with($lower, 'http://') || str_starts_with($lower, 'https://')) {
                $sites[] = $normalized;
                continue;
            }

            if (str_starts_with($lower, 'tel:')) {
                $phone = $this->normalize(substr($normalized, 4));
                if ($phone !== null) {
                    $phones[] = $phone;
                }
                continue;
            }

            if (str_contains($normalized, '@')) {
                $emails[] = $normalized;
                continue;
            }

            $phones[] = $normalized;
        }

        return [
            'emails' => array_values(array_unique($emails)),
            'phones' => array_values(array_unique($phones)),
            'sites' => array_values(array_unique($sites)),
        ];
    }

    /**
     * @return list<string>
     */
    public function splitRaw(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (is_array($raw)) {
            $result = [];
            foreach ($raw as $item) {
                array_push($result, ...$this->splitRaw($item));
            }

            return $result;
        }

        $text = trim((string) $raw, " \t\n\r\0\x0B'\"");

        if ($text === '') {
            return [];
        }

        $parts = preg_split('/[;,|\n\r]+/', $text) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts)));

        return $parts !== [] ? $parts : [$text];
    }
}
