<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

final class WbJobCities
{
    private const URL = 'https://job.wb.ru/assets/data/areas.json';

    /**
     * @return list<array{id: int, name: string}>
     */
    public function all(): array
    {
        $cities = [];

        $response = Http::timeout(30)
            ->acceptJson()
            ->get(self::URL);

        if (! $response->ok()) {
            throw new \RuntimeException('Не удалось получить список городов WB Job: HTTP ' . $response->status());
        }

        $areas = $response->json();
        if (! is_array($areas)) {
            throw new \RuntimeException('WB Job вернул некорректный список городов.');
        }

        foreach ($areas as $area) {
            if (! is_array($area)) {
                continue;
            }

            $id = filter_var($area['id'] ?? null, FILTER_VALIDATE_INT);
            $name = trim((string) ($area['name'] ?? ''));

            if ($id === false || $id < 1 || $name === '') {
                continue;
            }

            $cities[$id] = ['id' => $id, 'name' => $name];
        }

        if ($cities === []) {
            throw new \RuntimeException('WB Job не вернул ни одного города.');
        }

        return array_values($cities);
    }
}
