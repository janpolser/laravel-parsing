<?php

namespace App\Services\Ozon;

use Illuminate\Support\Facades\Http;

class VacancyCatalog
{
    public const URL = 'https://job-api.ozon.ru/v2/vacancy';

    /** @return array<int, array<string, mixed>> */
    public function collect(string $city, int $maxPages = 0): array
    {
        $rows = [];
        $expectedTotal = null;
        $expectedPages = null;
        $page = 1;

        do {
            $response = Http::acceptJson()->timeout(30)->connectTimeout(10)
                ->get(self::URL, ['city' => $city, 'meta.page' => $page, 'meta.limit' => 50]);
            if (!$response->ok()) {
                throw new \RuntimeException("Ozon: HTTP {$response->status()} при запросе страницы {$page}");
            }

            $body = $response->json();
            $items = is_array($body) ? ($body['items'] ?? null) : null;
            $meta = is_array($body) ? ($body['meta'] ?? null) : null;
            if (!is_array($items) || !array_is_list($items) || !is_array($meta)
                || !is_int($meta['page'] ?? null) || $meta['page'] !== $page
                || !is_int($meta['perPage'] ?? null) || $meta['perPage'] < 1
                || !is_int($meta['totalItems'] ?? null) || $meta['totalItems'] < 1
                || !is_int($meta['totalPages'] ?? null) || $meta['totalPages'] < 1
                || $meta['totalPages'] !== (int) ceil($meta['totalItems'] / $meta['perPage'])
                || $items === [] || count($items) > $meta['perPage']) {
                throw new \RuntimeException("Ozon: некорректный ответ страницы {$page}");
            }

            $expectedTotal ??= $meta['totalItems'];
            $expectedPages ??= $meta['totalPages'];
            if ($expectedTotal !== $meta['totalItems'] || $expectedPages !== $meta['totalPages']) {
                throw new \RuntimeException('Ozon: каталог изменился во время обхода');
            }

            foreach ($items as $item) {
                $id = is_array($item) ? ($item['internalUuid'] ?? null) : null;
                if (!is_string($id) || !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $id)
                    || !is_string($item['title'] ?? null) || trim($item['title']) === ''
                    || !is_string($item['vacancyType'] ?? null) || isset($rows[$id])) {
                    throw new \RuntimeException("Ozon: некорректная или повторная вакансия на странице {$page}");
                }
                $rows[$id] = $item;
            }

            $page++;
        } while ($page <= $expectedPages && ($maxPages === 0 || $page <= $maxPages));

        if (($maxPages === 0 || $maxPages >= $expectedPages) && count($rows) !== $expectedTotal) {
            throw new \RuntimeException('Ozon: число уникальных вакансий не совпадает с totalItems');
        }

        return array_values($rows);
    }
}
