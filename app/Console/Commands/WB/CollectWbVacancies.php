<?php

namespace App\Console\Commands\WB;

use App\Services\YandexFeedXmlFormat;
use App\Support\VacancyTextUrls;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class CollectWbVacancies extends Command
{
    protected $signature = 'wb:collect-wb-vacancies
        {--limit=50 : Запрашиваемый размер страницы (API может вернуть меньше)}
        {--start-offset=0 : С какого offset начинать}
        {--max-pages=0 : Максимум страниц, 0 — собрать весь каталог}
        {--outfile=wb_vacancies : Базовое имя xml-файла в storage/app}';

    protected $description = 'Собирает вакансии Wildberries и формирует YVL-совместимый XML';

    private const BASE_URL = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies';

    private const DETAIL_URL = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies/';

    private const HOST = 'career.wb.ru';

    private const TIMEZONE = 'Europe/Moscow';

    private CookieJar $cookieJar;

    private array $detailCache = [];

    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();

        $this->xmlFormatter = $xmlFormatter;
    }

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $offset = (int) $this->option('start-offset');
        $maxPages = (int) $this->option('max-pages');
        $outFileName = (string) $this->option('outfile') . today()->toDateString() . '.xml';

        if ($limit < 1 || $limit > 1000) {
            $this->error('Параметр --limit должен быть в диапазоне 1..1000');

            return self::INVALID;
        }
        if ($offset < 0 || $maxPages < 0) {
            $this->error('Параметры --start-offset и --max-pages должны быть >= 0');

            return self::INVALID;
        }

        $this->cookieJar = new CookieJar;
        $this->detailCache = [];

        try {
            $this->warmupSession();
            $normalizedRows = $this->collectPages($limit, $offset, $maxPages);

            if ($normalizedRows === []) {
                $this->error('Каталог пуст — предыдущий XML сохранён.');

                return self::FAILURE;
            }

            $this->info('В списке уникальных вакансий: ' . count($normalizedRows));
            $normalizedRows = $this->attachDetails($normalizedRows);
            if ($normalizedRows === []) {
                throw new \RuntimeException('Не найдена ни одна карточка вакансии — предыдущий XML сохранён');
            }
            $entities = array_map([$this, 'mapToEntity'], $normalizedRows);
            foreach ($entities as $index => $entity) {
                $entity = VacancyTextUrls::extractFromVacancy($entity);
                if (empty($entity['description'])) {
                    throw new \RuntimeException('Нет описания вакансии ID=' . $normalizedRows[$index]['id']);
                }
                $entities[$index] = $entity;
            }

            $outPath = storage_path('app/public/wb/' . $outFileName);
            $this->xmlFormatter->createXmlFeed($entities, self::HOST, $outPath);
            $this->info('Собрано уникальных вакансий: ' . count($entities));
            $this->info("XML сформирован: {$outPath}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор WB не завершён: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    private function warmupSession(): void
    {
        $this->info('Прогреваю сессию career.wb.ru...');
        $warmup = Http::withOptions([
            'cookies' => $this->cookieJar,
            'curl' => [
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            ],
        ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Referer' => 'https://career.wb.ru/',
                'Origin' => 'https://career.wb.ru',
            ])
            ->get('https://career.wb.ru/');

        $this->info("Warmup status: {$warmup->status()}");
    }

    private function collectPages(int $limit, int $offset, int $maxPages): array
    {
        $normalizedRows = [];
        $seenIds = [];
        $pagesFetched = 0;
        $total = null;
        $startOffset = $offset;

        while ($maxPages === 0 || $pagesFetched < $maxPages) {
            $resp = $this->fetchVacancyPage($limit, $offset);
            if (!$resp->ok()) {
                throw new \RuntimeException("HTTP {$resp->status()} при запросе offset={$offset}");
            }

            $json = $resp->json();
            $data = is_array($json) ? ($json['data'] ?? null) : null;
            $range = is_array($data) ? ($data['range'] ?? null) : null;
            $items = is_array($data) ? ($data['items'] ?? null) : null;
            if (!is_array($range) || !is_array($items) || !array_is_list($items)
                || !isset($range['count'], $range['offset'])
                || !is_int($range['count']) || $range['count'] < 0
                || !is_int($range['offset']) || $range['offset'] !== $offset) {
                throw new \RuntimeException("Некорректный ответ списка на offset={$offset}");
            }

            $total ??= $range['count'];
            if ($range['count'] !== $total) {
                throw new \RuntimeException('Количество вакансий изменилось во время обхода');
            }
            if ($items === []) {
                if ($offset < $total) {
                    throw new \RuntimeException("Пустая страница на offset={$offset}, всего вакансий: {$total}");
                }
                break;
            }

            foreach ($items as $row) {
                $id = is_array($row) ? ($row['id'] ?? null) : null;
                if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1
                    || !is_string($row['name'] ?? null) || trim($row['name']) === '') {
                    throw new \RuntimeException("Некорректная вакансия на offset={$offset}");
                }
                if (isset($seenIds[(int) $id])) {
                    throw new \RuntimeException("API повторил вакансию ID={$id}");
                }
                $seenIds[(int) $id] = true;
                $normalizedRows[] = $this->normalizeRow($row);
            }

            $this->line("Страница: offset={$offset}, получено: " . count($items) . ", всего: {$total}");
            // The API caps its response at 50 even when range.limit echoes 500.
            $offset += count($items);
            $pagesFetched++;
            if ($offset > $total) {
                throw new \RuntimeException('API вернул больше вакансий, чем указано в range.count');
            }
            if ($offset === $total) {
                break;
            }
        }

        if (($startOffset > 0 || $offset < $total) && $this->option('outfile') === 'wb_vacancies') {
            throw new \RuntimeException('Частичный обход не заменяет полный фид: задайте отдельное имя через --outfile');
        }
        if ($offset < $total) {
            $this->warn("Достигнут --max-pages={$maxPages}: собрана только часть каталога");
        } elseif (count($normalizedRows) !== max(0, $total - $startOffset)) {
            throw new \RuntimeException('Не удалось собрать все уникальные вакансии каталога');
        }

        return $normalizedRows;
    }

    private function fetchVacancyPage(int $limit, int $offset)
    {
        return Http::withOptions([
            'cookies' => $this->cookieJar,
            'curl' => [
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            ],
        ])->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Referer' => 'https://career.wb.ru/',
            'Origin' => 'https://career.wb.ru',
        ])->get(self::BASE_URL, [
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    private function normalizeRow(array $item): array
    {
        $employmentTypes = [];
        if (!empty($item['employment_types']) && is_array($item['employment_types'])) {
            foreach ($item['employment_types'] as $type) {
                if (is_array($type) && isset($type['title'])) {
                    $employmentTypes[] = $type['title'];
                }
            }
        }

        return [
            'id' => $item['id'] ?? null,
            'name' => $item['name'] ?? null,
            'direction_title' => $item['direction_title'] ?? null,
            'direction_role_title' => $item['direction_role_title'] ?? null,
            'experience_type_title' => $item['experience_type_title'] ?? null,
            'city_title' => $item['city_title'] ?? null,
            'employment_types' => empty($employmentTypes) ? null : implode(', ', $employmentTypes),
            'url' => 'https://career.wb.ru/vacancies/' . ($item['id'] ?? ''),
        ];
    }

    private function attachDetails(array $rows): array
    {
        $availableRows = [];
        $missing = 0;
        foreach ($rows as $row) {
            $detail = $this->fetchVacancyDetail((int) $row['id']);
            if ($detail === null) {
                $missing++;
                $this->warn('Карточка вакансии ID=' . $row['id'] . ' не найдена (HTTP 404), пропускаю');

                continue;
            }
            $row['detail'] = $detail;
            $availableRows[] = $row;
        }
        $this->info('Доступных карточек: ' . count($availableRows) . "; не найдены (HTTP 404): {$missing}");

        return $availableRows;
    }

    private function fetchVacancyDetail(int $id): ?array
    {
        if ($id === 0) {
            return [];
        }

        if (isset($this->detailCache[$id])) {
            return $this->detailCache[$id];
        }

        $response = Http::withOptions([
            'cookies' => $this->cookieJar,
            'curl' => [
                CURLOPT_TIMEOUT => 20,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            ],
        ])->withHeaders([
            'Accept' => 'application/json',
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Referer' => 'https://career.wb.ru/',
            'Origin' => 'https://career.wb.ru',
        ])->get(self::DETAIL_URL . $id);

        if ($response->status() === 404) {
            return null;
        }
        if (!$response->ok()) {
            throw new \RuntimeException("HTTP {$response->status()} при запросе вакансии ID={$id}");
        }
        $data = $response->json()['data'] ?? null;
        if (!is_array($data) || $data === []) {
            throw new \RuntimeException("Некорректные детали вакансии ID={$id}");
        }

        return $this->detailCache[$id] = $data;
    }

    private function mapToEntity(array $row): array
    {
        $detail = $row['detail'] ?? [];
        $salaryFrom = $detail['salary_from'] ?? null;
        $salaryTo = $detail['salary_to'] ?? null;
        $hasSalary = $salaryFrom || $salaryTo;
        $description = $this->composeDescription($detail);
        if (!$description) {
            return [];
        }

        $entity = [
            'url' => $row['url'],
            'mobile_url' => $row['url'],
            'creation_date' => $this->formatNow(),
            'update_date' => $this->formatNow(),
            'salary' => $hasSalary ? $this->formatSalary($salaryFrom, $salaryTo) : null,
            'currency' => $hasSalary ? 'RUR' : null,
            'category' => $this->buildCategory($row),
            'job_name' => $row['name'],
            'employment' => $row['employment_types'],
            'schedule' => null,
            'description' => $description,
            'duty' => $this->composeArrayString($detail['duties_arr'] ?? []),
            'term' => $this->buildTerm($detail),
            'requirement' => $this->buildRequirement($row, $detail),
            'addresses' => $this->buildAddresses($row),
            'company_name' => 'Wildberries',
            'company_description' => 'Wildberries — маркетплейс, работающий по всей России.',
            'hr_agency' => false,
            'campaign' => $this->composeCampaign($row),
        ];

        return array_filter($entity, function ($value) {
            if ($value === null || $value === '') {
                return false;
            }
            if (is_array($value) && empty($value)) {
                return false;
            }

            return true;
        });
    }

    private function composeDescription(array $detail): ?string
    {
        $parts = [];
        if (!empty($detail['description'])) {
            $parts[] = $this->normalizeText($detail['description']);
        }
        if (!empty($detail['requirements_arr']) && is_array($detail['requirements_arr'])) {
            $parts[] = $this->composeArrayString($detail['requirements_arr']);
        }
        if (!empty($detail['conditions_arr']) && is_array($detail['conditions_arr'])) {
            $parts[] = $this->composeArrayString($detail['conditions_arr']);
        }

        return $parts ? implode("\n\n", array_filter($parts)) : null;
    }

    private function composeArrayString(array $values): ?string
    {
        $clean = [];
        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $text = $this->normalizeText((string) $value);
            if ($text === null || $text === '') {
                continue;
            }

            $text = rtrim($text, ';');
            $text = trim($text);
            if ($text === '') {
                continue;
            }

            $clean[] = $text;
        }

        if (empty($clean)) {
            return null;
        }

        return implode(";\n", $clean);
    }

    private function buildCategory(array $row): array
    {
        $category = [];
        if (!empty($row['direction_title'])) {
            $category['industry'] = $row['direction_title'];
        }
        if (!empty($row['direction_role_title'])) {
            $category['specialization'] = $row['direction_role_title'];
        }

        return $category;
    }

    private function buildTerm(array $detail): array
    {
        return array_filter([
            'contract' => null,
            'text' => $this->composeArrayString($detail['conditions_arr'] ?? []),
        ]);
    }

    private function buildRequirement(array $row, array $detail): array
    {
        return array_filter([
            'age' => null,
            'sex' => null,
            'education' => null,
            'experience' => $detail['experience_type_title'] ?? $row['experience_type_title'] ?? null,
            'qualification' => $this->composeArrayString($detail['requirements_arr'] ?? []),
        ]);
    }

    private function buildAddresses(array $row): array
    {
        $location = $row['city_title'] ?? null;
        if (!$location) {
            return [];
        }

        return [[
            'location' => $this->normalizeText($location),
            'metro' => null,
            'lng' => null,
            'lat' => null,
        ]];
    }

    private function composeCampaign(array $row): string
    {
        return 'Wildberries #' . ($row['id'] ?? '');
    }

    private function formatNow(): string
    {
        $now = Carbon::now(self::TIMEZONE);

        return $now->format('Y-m-d H:i:s') . ' GMT' . $this->formatOffset($now);
    }

    private function formatSalary(?string $from, ?string $to): string
    {
        $parts = [];
        if ($from) {
            $parts[] = "от {$from}";
        }
        if ($to) {
            $parts[] = "до {$to}";
        }

        return $parts ? implode(' ', $parts) : 'По договоренности';
    }

    private function formatOffset(Carbon $date): string
    {
        $offsetMinutes = $date->offsetMinutes;
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $absMinutes = abs($offsetMinutes);
        $hours = intdiv($absMinutes, 60);
        $minutes = $absMinutes % 60;

        $result = $sign . $hours;
        if ($minutes) {
            $result .= ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT);
        }

        return $result;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*(p|div|li|ul|ol|h[1-6]|section|article|tr)\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
