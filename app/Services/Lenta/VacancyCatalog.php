<?php

namespace App\Services\Lenta;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class VacancyCatalog
{
    public const URL = 'https://career-api.star.lenta.com/api/v1/search/table/';

    /** @return array{cities:list<array{cityId:int,title:string,count:int}>,items:list<array<string,mixed>>,hidden:int,duplicates:int} */
    public function collect(int $maxCities = 0, int $sleepMs = 100): array
    {
        $discovery = $this->request(451, 0, 1);
        $cities = $this->parseCities($discovery);
        if ($maxCities > 0) {
            $cities = array_slice($cities, 0, $maxCities);
        }

        $items = [];
        $hidden = 0;
        $duplicates = 0;
        foreach ($cities as $index => $city) {
            $page = 0;
            $totalPages = null;
            do {
                $response = $this->request($city['cityId'], $page, 100);
                $result = $this->parseResult($response, $city['cityId'], $page);
                $totalPages ??= $result['totalPagesCount'];
                if ($totalPages !== $result['totalPagesCount']) {
                    throw new \RuntimeException('Lenta: изменилось число страниц города '.$city['cityId']);
                }
                foreach ($result['items'] as $item) {
                    $id = $item['vacancyId'];
                    if (($item['isHideOnCareer'] ?? false) === true) {
                        $hidden++;
                    } elseif (isset($items[$id])) {
                        $duplicates++;
                    } else {
                        $items[$id] = $item;
                    }
                }
                $page++;
            } while ($page < $totalPages);

            if ($sleepMs > 0 && $index < count($cities) - 1) {
                usleep($sleepMs * 1000);
            }
        }

        return ['cities' => $cities, 'items' => array_values($items), 'hidden' => $hidden, 'duplicates' => $duplicates];
    }

    private function request(int $cityId, int $page, int $perPage): Response
    {
        $response = Http::acceptJson()->timeout(60)->connectTimeout(15)->retry(3, 1000, throw: false)
            ->get(self::URL, ['cityId' => $cityId, 'countItemsPerPage' => $perPage, 'page' => $page]);
        if (!$response->ok()) {
            throw new \RuntimeException("Lenta: HTTP {$response->status()} для города {$cityId}, страницы {$page}");
        }

        return $response;
    }

    /** @return list<array{cityId:int,title:string,count:int}> */
    private function parseCities(Response $response): array
    {
        $cities = $response->json('filters.cities');
        if (!is_array($cities) || $cities === []) {
            throw new \RuntimeException('Lenta: в ответе нет справочника городов');
        }
        $result = [];
        foreach ($cities as $city) {
            $id = is_array($city) ? ($city['cityId'] ?? null) : null;
            $title = is_array($city) ? ($city['title'] ?? null) : null;
            $count = is_array($city) ? ($city['count'] ?? null) : null;
            if (!is_int($id) || $id < 1 || !is_string($title) || trim($title) === ''
                || (!is_int($count) && !(is_string($count) && ctype_digit($count))) || (int) $count < 0) {
                throw new \RuntimeException('Lenta: некорректная запись города');
            }
            if ((int) $count > 0) {
                $result[] = ['cityId' => $id, 'title' => trim($title), 'count' => (int) $count];
            }
        }
        if ($result === []) {
            throw new \RuntimeException('Lenta: справочник городов не содержит вакансий');
        }

        return $result;
    }

    /** @return array{items:list<array<string,mixed>>,totalPagesCount:int} */
    private function parseResult(Response $response, int $cityId, int $page): array
    {
        $result = $response->json('searchResult');
        $items = is_array($result) ? ($result['items'] ?? null) : null;
        $currentPage = is_array($result) ? ($result['currentPage'] ?? null) : null;
        $totalPages = is_array($result) ? ($result['totalPagesCount'] ?? null) : null;
        $totalCount = is_array($result) ? ($result['totalCount'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items) || !is_int($currentPage) || $currentPage !== $page
            || !is_int($totalPages) || $totalPages < 1 || !is_int($totalCount) || $totalCount < 1
            || $page >= $totalPages || ($page === $totalPages - 1 && $items === [])) {
            throw new \RuntimeException("Lenta: некорректный ответ города {$cityId}, страницы {$page}");
        }
        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['vacancyId'] ?? null) || $item['vacancyId'] < 1
                || !is_string($item['title'] ?? null) || trim($item['title']) === ''
                || !is_string($item['cityTitle'] ?? null) || trim($item['cityTitle']) === '') {
                throw new \RuntimeException("Lenta: некорректная вакансия города {$cityId}");
            }
        }

        return ['items' => $items, 'totalPagesCount' => $totalPages];
    }
}
