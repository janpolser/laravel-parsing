<?php

namespace App\Console\Commands\Perekrestok;

use App\Services\YandexFeedXmlFormat;
use App\Support\FeedCountTolerance;
use App\Support\VacancyTextUrls;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class CollectVacancies extends Command
{
    private const LIST_URL = 'https://rabota5ka.ru/gw/api/vacancy/hire-request';

    protected $signature = 'perekrestok:collect-vacancies
        {--limit=100 : Количество вакансий на странице API}
        {--max-pages=0 : Максимум страниц для preview, 0 — все}
        {--radius=30 : Радиус поиска магазинов}
        {--warehouse-radius=100 : Радиус поиска складов}
        {--outfile=perekrestok_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии Перекрёстка через API и формирует YVL XML';

    public function handle(YandexFeedXmlFormat $formatter): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        $radius = filter_var($this->option('radius'), FILTER_VALIDATE_INT);
        $warehouseRadius = filter_var($this->option('warehouse-radius'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');

        if ($limit === false || $limit < 1 || $limit > 100
            || $maxPages === false || $maxPages < 0
            || $radius === false || $radius < 0
            || $warehouseRadius === false || $warehouseRadius < 0
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры пагинации, радиусов или имени файла');

            return self::INVALID;
        }

        $preview = $maxPages > 0 || $outfile !== 'perekrestok_vacancies';

        try {
            $first = $this->fetchPage(1, $limit, $radius, $warehouseRadius);
            $total = $this->positiveInt($first['total'] ?? null, 'total');
            $totalPages = $this->positiveInt($first['totalPages'] ?? null, 'totalPages');
            if ((int) ($first['page'] ?? 0) !== 1 || (int) ($first['limit'] ?? 0) !== $limit
                || $totalPages !== max(1, (int) ceil($total / $limit))) {
                throw new \RuntimeException('API вернуло противоречивую пагинацию на первой странице');
            }

            $pages = $maxPages > 0 ? min($maxPages, $totalPages) : $totalPages;
            $items = $first['items'] ?? null;
            if (!is_array($items) || $items === []) {
                throw new \RuntimeException('В ответе API нет массива items');
            }
            for ($page = 2; $page <= $pages; $page++) {
                $payload = $this->fetchPage($page, $limit, $radius, $warehouseRadius);
                if ((int) ($payload['page'] ?? 0) !== $page
                    || ! FeedCountTolerance::allows($total, (int) ($payload['total'] ?? 0))
                    || abs((int) ($payload['totalPages'] ?? 0) - $totalPages) > 1
                    || !isset($payload['items']) || !is_array($payload['items']) || $payload['items'] === []) {
                    throw new \RuntimeException("API вернуло неполную страницу {$page}");
                }
                if (array_diff(array_column($payload['items'], 'id'), array_column($items, 'id')) === []) {
                    throw new \RuntimeException("API вернуло повторную страницу {$page}");
                }
                $totalPages = (int) $payload['totalPages'];
                $pages = $maxPages > 0 ? min($maxPages, $totalPages) : $totalPages;
                $items = array_merge($items, $payload['items']);
                $this->line("Страница {$page}/{$pages}: вакансий " . count($payload['items']));
            }

            if ($maxPages === 0 && ! FeedCountTolerance::allows($total, count($items))) {
                throw new \RuntimeException('Получено вакансий ' . count($items) . " вместо {$total}");
            }

            $entities = [];
            $seen = [];
            foreach ($items as $item) {
                $id = (string) ($item['id'] ?? '');
                if ($id !== '' && isset($seen[$id])) {
                    $this->warn("Повторный id вакансии {$id}; запись пропущена");

                    continue;
                }
                $entity = $this->mapVacancy($item);
                if ($id !== '') {
                    $seen[$id] = true;
                }
                if ($entity === null) {
                    continue;
                }
                $entities[] = $entity;
            }

            if ($maxPages === 0 && ! FeedCountTolerance::allows($total, count($seen))) {
                throw new \RuntimeException('Количество уникальных вакансий существенно отличается от счётчика');
            }

            if ($entities === []) {
                throw new \RuntimeException('Не найдено ни одной полной вакансии Перекрёстка');
            }

            $dir = $preview ? 'app/perekrestok-previews/' : 'app/public/perekrestok/';
            $path = storage_path($dir . $outfile . today('Europe/Moscow')->toDateString() . '.xml');
            $formatter->createXmlFeed($entities, 'rabota.perekrestok.ru', $path);
            $this->info("Всего записей API: {$total}; страниц: {$pages}; в XML: " . count($entities));
            $this->info('XML сформирован: ' . $path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Перекрёстка не завершён; предыдущий фид сохранён: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function fetchPage(int $page, int $limit, int $radius, int $warehouseRadius): array
    {
        $query = http_build_query([
            'page' => $page,
            'limit' => $limit,
            'radius' => $radius,
            'warehouseRadius' => $warehouseRadius,
            'portals' => ['cross'],
        ], '', '&', PHP_QUERY_RFC3986);
        $response = Http::acceptJson()->timeout(30)->connectTimeout(10)
            ->retry(3, 500, throw: false)
            ->get(self::LIST_URL . '?' . $query);
        if (!$response->ok()) {
            throw new \RuntimeException("HTTP {$response->status()} при загрузке страницы {$page}");
        }
        $payload = $response->json();
        if (!is_array($payload)) {
            throw new \RuntimeException("Некорректный JSON на странице {$page}");
        }

        return $payload;
    }

    private function mapVacancy(array $item): ?array
    {
        $id = $item['id'] ?? null;
        $name = $item['name'] ?? $item['vacancy']['name'] ?? null;
        $description = $item['vacancy']['description'] ?? $item['description'] ?? null;
        if (!is_string($id) || $id === '' || !is_string($name) || trim($name) === '') {
            throw new \RuntimeException('Вакансия без id или названия');
        }
        $description = $this->description($description);
        if ($description === '') {
            $this->warn("Пропущена вакансия без описания: {$id}");

            return null;
        }

        $url = 'https://rabota.perekrestok.ru/vacancies/' . rawurlencode($id);
        $salaryFrom = $item['salaryFrom'] ?? null;
        $salaryTo = $item['salaryTo'] ?? null;
        $salary = trim(($salaryFrom !== null ? 'от ' . $salaryFrom : '')
            . ($salaryTo !== null ? ($salaryFrom !== null ? ' до ' : 'до ') . $salaryTo : ''));
        $city = is_array($item['city'] ?? null) ? ($item['city']['name'] ?? '') : '';
        $location = trim((string) ($item['address'] ?? ''));
        if ($location === '') {
            $location = $city;
        } elseif ($city !== '' && !str_contains(mb_strtolower($location), mb_strtolower($city))) {
            $location .= ', ' . $city;
        }
        if ($location === '') {
            throw new \RuntimeException("Вакансия {$id} без адреса");
        }
        $coordinates = $item['position']['coordinates'] ?? [];
        $schedule = $item['schedule'] ?? $item['subSchedule'] ?? [];
        $schedule = is_array($schedule) ? implode(', ', array_filter($schedule, 'is_string')) : (string) $schedule;

        return array_filter([
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s') . ' GMT+3',
            'salary' => $salary ?: null,
            'currency' => $salary ? 'RUB' : null,
            'category' => ['industry' => $this->industry($item)],
            'job_name' => trim($name),
            'schedule' => $schedule ?: null,
            'description' => VacancyTextUrls::extractFromVacancy(['description' => $description])['description'] ?? $description,
            'text_urls' => VacancyTextUrls::extractFromVacancy(['description' => $description])['text_urls'] ?? [],
            'addresses' => [[
                'location' => $location,
                'lng' => $coordinates[0] ?? null,
                'lat' => $coordinates[1] ?? null,
            ]],
            'company_name' => 'Перекрёсток',
            'company_site' => 'https://www.x5.ru/',
            'hr_agency' => false,
            'campaign' => 'Перекрёсток',
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function description(mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return trim(strip_tags($value));
        }
        $parts = [];
        $walk = function (mixed $node) use (&$walk, &$parts): void {
            if (is_array($node)) {
                if (isset($node['text']) && is_string($node['text']) && trim($node['text']) !== '') {
                    $parts[] = trim($node['text']);
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            }
        };
        $walk($decoded);

        $description = trim(implode("\n", array_values(array_unique($parts))));

        return trim((string) preg_replace('/^Описание вакансии:\s*/ui', '', $description));
    }

    private function industry(array $item): string
    {
        $direction = (string) ($item['jobDirection'] ?? $item['vacancy']['jobDirection'] ?? '');

        return [
            'warehouse' => 'Склад',
            'stores' => 'Магазины',
            'production' => 'Производство',
        ][$direction] ?? $direction;
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if (!is_int($value) && !is_string($value) || !ctype_digit((string) $value) || (int) $value < 1) {
            throw new \RuntimeException("Некорректное поле API {$field}");
        }

        return (int) $value;
    }
}
