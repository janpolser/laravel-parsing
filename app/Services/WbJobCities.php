<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class WbJobCities
{
    private const URL = 'https://wbk.wb.ru/community-utils/api/feedback/city';
    private const PAGE_SIZE = 500;

    /**
     * @return list<array{id: int, name: string}>
     */
    public function all(): array
    {
        $cities = [];
        $offset = 0;

        do {
            $response = Http::timeout(25)
                ->acceptJson()
                ->asJson()
                ->post(self::URL, [
                    'search' => '',
                    'limit' => self::PAGE_SIZE,
                    'offset' => $offset,
                ]);

            if (! $response->ok()) {
                throw new \RuntimeException('Не удалось получить список городов WB: HTTP ' . $response->status());
            }

            $batch = $response->json('cities', []);
            if (! is_array($batch)) {
                throw new \RuntimeException('WB вернул некорректный список городов.');
            }

            foreach ($batch as $city) {
                $id = filter_var($city['id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim((string) ($city['city'] ?? ''));

                if ($id === false || $id < 1 || $name === '') {
                    continue;
                }

                $cities[$id] = ['id' => $id, 'name' => $name];
            }

            $offset += count($batch);
        } while (count($batch) === self::PAGE_SIZE);

        if ($cities === []) {
            throw new \RuntimeException('WB не вернул ни одного города.');
        }

        return array_values($cities);
    }
}
