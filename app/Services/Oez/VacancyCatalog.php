<?php

namespace App\Services\Oez;

use App\Support\FeedCountTolerance;
use Illuminate\Support\Facades\Http;

class VacancyCatalog
{
    public const URL = 'https://api.hh.ru/vacancies';

    private const EMPLOYER_ID = '68587';

    /** @return array<int, array<string, mixed>> */
    public function collect(int $maxPages = 0, int $perPage = 100, int $searchPeriod = 1): array
    {
        if ($perPage < 1 || $perPage > 100 || $searchPeriod < 1) {
            throw new \InvalidArgumentException('Некорректные параметры HH API');
        }

        $token = trim((string) config('services.hh.api_token'));
        if ($token === '') {
            throw new \RuntimeException('Не задан HH_API_TOKEN');
        }

        $items = [];
        $totalPages = null;
        $found = null;
        $page = 0;
        do {
            $response = Http::acceptJson()->withToken($token)->timeout(30)->connectTimeout(10)
                ->retry(2, 1000, throw: false)
                ->get(self::URL, [
                    'employer_id' => self::EMPLOYER_ID,
                    'per_page' => $perPage,
                    'page' => $page,
                    'search_period' => $searchPeriod,
                    'text' => '*',
                    'search_field' => ['name', 'company_name', 'description'],
                ]);

            if (!$response->ok()) {
                throw new \RuntimeException("HH: HTTP {$response->status()} на странице {$page}");
            }

            $body = $response->json();
            $pageItems = is_array($body) ? ($body['items'] ?? null) : null;
            $bodyPages = is_array($body) ? ($body['pages'] ?? null) : null;
            $bodyFound = is_array($body) ? ($body['found'] ?? null) : null;
            if (!is_array($pageItems) || !array_is_list($pageItems)
                || !is_int($bodyPages) || $bodyPages < 1
                || !is_int($bodyFound) || $bodyFound < 1
                || $page >= $bodyPages || count($pageItems) > $perPage) {
                throw new \RuntimeException("HH: некорректный ответ страницы {$page}");
            }

            $totalPages ??= $bodyPages;
            $found ??= $bodyFound;
            if (abs($totalPages - $bodyPages) > 1 || ! FeedCountTolerance::allows($found, $bodyFound)) {
                throw new \RuntimeException('HH: каталог изменился во время обхода');
            }
            $totalPages = $bodyPages;

            $beforePage = count($items);
            foreach ($pageItems as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (!is_array($item) || !is_string($id) || !ctype_digit($id)) {
                    throw new \RuntimeException("HH: некорректная или повторная вакансия на странице {$page}");
                }
                $items[$id] = $item;
            }
            if (count($items) === $beforePage) {
                throw new \RuntimeException("HH: страница {$page} не содержит новых вакансий");
            }
            $page++;
        } while ($page < $totalPages && ($maxPages === 0 || $page < $maxPages));

        if (($maxPages === 0 || $maxPages >= $totalPages) && ! FeedCountTolerance::allows(min($found, 2000), count($items))) {
            throw new \RuntimeException('HH: число уникальных вакансий не совпадает с доступным лимитом API');
        }

        return array_values($items);
    }
}
