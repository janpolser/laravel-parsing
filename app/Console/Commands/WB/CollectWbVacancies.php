<?php

namespace App\Console\Commands\WB;

use App\Services\YandexFeedXmlFormat;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class CollectWbVacancies extends Command
{
    protected $signature = 'wb:collect-wb-vacancies
        {--limit=500 : Размер страницы (макс. 1000)}
        {--start-offset=0 : С какого offset начинать}
        {--max-pages=1 : Сколько страниц тянуть (1 — только один запрос)}
        {--outfile=wb_vacancies : Базовое имя xml-файла в storage/app}';

    protected $description = 'Собирает вакансии Wildberries и формирует YVL-совместимый XML';

    private const BASE_URL = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies';
    private const DETAIL_URL = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies/';
    private const HOST = 'career.wb.ru';
    private const TIMEZONE = 'Europe/Moscow';

    private CookieJar $cookieJar;
    private array $detailCache = [];
    private array $detailByName = [];
    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();

        $this->xmlFormatter = $xmlFormatter;
    }

    public function handle(): int
    {
        $limit       = (int) $this->option('limit');
        $offset      = (int) $this->option('start-offset');
        $maxPages    = (int) $this->option('max-pages');
        $outFileName = (string) $this->option('outfile') . today() . '.xml';

        if ($limit < 1 || $limit > 1000) {
            $this->error('Параметр --limit должен быть в диапазоне 1..1000');
            return self::INVALID;
        }
        if ($maxPages < 1) {
            $this->error('Параметр --max-pages должен быть >= 1');
            return self::INVALID;
        }

        $this->cookieJar = new CookieJar();

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
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Referer'    => 'https://career.wb.ru/',
                'Origin'     => 'https://career.wb.ru',
            ])
            ->get('https://career.wb.ru/');

        $this->info("Warmup status: {$warmup->status()}");

        $normalizedRows = [];
        $pagesFetched = 0;

        while ($pagesFetched < $maxPages) {
            $resp = $this->fetchVacancyPage($limit, $offset);
            if (!$resp->ok()) {
                $this->error("HTTP {$resp->status()} при запросе оффсета={$offset}");
                return self::FAILURE;
            }

            $json = $resp->json();
            $items = $this->extractItems($json);

            if (empty($items)) {
                $this->info("Пусто на offset={$offset}. Останавливаюсь.");
                break;
            }

            foreach ($items as $row) {
                if (is_array($row)) {
                    $normalizedRows[] = $this->normalizeRow($row);
                }
            }

            $this->line("Страница: offset={$offset}, получено: " . count($items));
            $offset += $limit;
            $pagesFetched++;
        }

        if (empty($normalizedRows)) {
            $this->warn('Данных нет — писать нечего.');
            return self::SUCCESS;
        }

        $this->attachDetailsByName($normalizedRows);

        $entities = array_filter(array_map([$this, 'mapToEntity'], $normalizedRows));

        $outPath = storage_path('app/' . $outFileName);
        $this->xmlFormatter->createXmlFeed($entities, self::HOST, $outPath);

        $this->info("XML сформирован: {$outPath}");
        return self::SUCCESS;
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

    private function extractItems(array $json): array
    {
        if (!empty($json['data']['items']) && is_array($json['data']['items'])) {
            return $json['data']['items'];
        }

        if (!empty($json['items']) && is_array($json['items'])) {
            return $json['items'];
        }

        return [];
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

    private function attachDetailsByName(array &$rows): void
    {
        foreach ($rows as $index => $row) {
            $name = $row['name'];
            if ($name && isset($this->detailByName[$name])) {
                $rows[$index]['detail'] = $this->detailByName[$name];
                continue;
            }

            $detail = $this->fetchVacancyDetail((int)$row['id']);
            if ($name) {
                $this->detailByName[$name] = $detail;
            }
            $rows[$index]['detail'] = $detail;
        }
    }

    private function fetchVacancyDetail(int $id): array
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

        $data = $response->json()['data'] ?? [];
        return $this->detailCache[$id] = is_array($data) ? $data : [];
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
            $parts[] = $this->escapeForXml($detail['description']);
        }
        if (!empty($detail['requirements_arr']) && is_array($detail['requirements_arr'])) {
            $parts[] = $this->composeArrayString($detail['requirements_arr']);
        }
        if (!empty($detail['conditions_arr']) && is_array($detail['conditions_arr'])) {
            $parts[] = $this->composeArrayString($detail['conditions_arr']);
        }

        return $parts ? implode("\n", $parts) : null;
    }

    private function composeArrayString(array $values): ?string
    {
        $clean = [];
        foreach ($values as $value) {
            $text = trim($value);
            if ($text === '') {
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

        return $this->escapeForXml(implode('; ', $clean));
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
            'location' => $this->escapeForXml($location),
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

    private function escapeForXml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);
        return str_replace(
            ['"', '&', '>', '<', '\''],
            ['&quot;', '&amp;', '&gt;', '&lt;', '&apos;'],
            trim($text)
        );
    }
}
