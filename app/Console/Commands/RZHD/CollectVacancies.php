<?php

namespace App\Console\Commands\RZHD;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class CollectVacancies extends Command
{
    protected $signature = 'rzhd:collect-vacancies {--outfile=rzhd_vacancies : Базовое имя xml-файла в storage/app}';

    protected $description = 'Собирает вакансии с team.rzd.ru и генерирует YVL-совместимый XML через сервис';

    private const BASE_URL = 'https://team.rzd.ru/api/v1/career/vacancies';

    private const LIST_PAGE_SIZE = 500;

    private const HOST = 'team.rzd.ru';

    private const TIMEZONE = 'Europe/Moscow';

    private const EMPLOYMENT_LABELS = [
        'full' => 'полная',
        'part' => 'частичная',
        'temporary' => 'временная',
        'internship' => 'стажировка',
    ];

    private const SCHEDULE_LABELS = [
        'shift' => 'сменный',
        'flexible' => 'гибкий',
        'remote' => 'удаленная работа',
        'fully' => 'полная',
        'day' => 'дневной',
    ];

    private array $detailCache = [];

    private array $detailByTitle = [];

    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();

        $this->xmlFormatter = $xmlFormatter;
    }

    public function handle(): int
    {
        $outFileName = (string) $this->option('outfile') . today()->toDateString() . '.xml';

        $items = $this->collectVacancyPages();
        if ($items === null) {
            return self::FAILURE;
        }

        $normalized = array_map([$this, 'normalizeRow'], $items);
        $this->attachDetailsByTitle($normalized);

        $entities = array_filter(array_map([$this, 'mapToEntity'], $normalized));
        $outPath = storage_path('app/public/rzhd/' . $outFileName);
        $this->xmlFormatter->createXmlFeed($entities, self::HOST, $outPath);

        $this->info("XML сформирован: {$outPath}");

        return self::SUCCESS;
    }

    private function collectVacancyPages(): ?array
    {
        $items = [];
        $expectedCount = null;
        $expectedPages = null;
        $page = 1;

        do {
            $response = $this->sendVacancyRequest($page, self::LIST_PAGE_SIZE);
            if (!$response->ok()) {
                $this->error("Ошибка запроса страницы {$page}: HTTP " . $response->status());

                return null;
            }

            $payload = $response->json();
            $meta = is_array($payload) ? ($payload['meta'] ?? null) : null;
            $pageItems = is_array($payload) ? ($payload['data'] ?? null) : null;
            if (!is_array($meta) || !is_array($pageItems) || $pageItems === []) {
                $this->error("Неполная или некорректная страница вакансий: {$page}");

                return null;
            }

            $positiveInteger = ['options' => ['min_range' => 1]];
            $count = filter_var($meta['count'] ?? null, FILTER_VALIDATE_INT, $positiveInteger);
            $pages = filter_var($meta['pages'] ?? null, FILTER_VALIDATE_INT, $positiveInteger);
            $reportedPage = filter_var($meta['page'] ?? null, FILTER_VALIDATE_INT, $positiveInteger);

            if ($count === false || $pages === false || $reportedPage !== $page) {
                $this->error("Неполная или некорректная страница вакансий: {$page}");

                return null;
            }

            if ($expectedCount === null) {
                $expectedCount = $count;
                $expectedPages = $pages;
                $this->info("Всего вакансий: {$expectedCount}, страниц: {$expectedPages}");
            } elseif ($count !== $expectedCount || $pages !== $expectedPages) {
                $this->error('Каталог вакансий изменился во время обхода; XML не сформирован.');

                return null;
            }

            foreach ($pageItems as $item) {
                $id = is_array($item)
                    ? filter_var($item['id'] ?? null, FILTER_VALIDATE_INT, $positiveInteger)
                    : false;
                if ($id === false || isset($items[$id])) {
                    $this->error("Некорректный или повторный ID вакансии на странице {$page}");

                    return null;
                }

                $items[$id] = $item;
            }

            $this->line("Страница {$page}/{$expectedPages}: получено " . count($pageItems) . ', всего ' . count($items));
            $page++;
        } while ($page <= $expectedPages);

        if (count($items) !== $expectedCount) {
            $this->error("Неполный каталог вакансий: ожидалось {$expectedCount}, получено " . count($items));

            return null;
        }

        return array_values($items);
    }

    private function sendVacancyRequest(int $page, int $perPage)
    {
        return $this->rzhdHttpClient()->get(self::BASE_URL, [
            'page' => $page,
            'per_page' => $perPage,
            'sort' => 'date_desc',
        ]);
    }

    private function normalizeRow(array $item): array
    {
        return [
            'id' => $item['id'] ?? null,
            'position_id' => $item['position_id'] ?? null,
            'position_title' => $item['position_title'] ?? null,
            'salary_from' => $item['salary_from'] ?? null,
            'salary_to' => $item['salary_to'] ?? null,
            'salary_month' => $item['salary_month'] ?? null,
            'schedule' => $item['schedule'] ?? null,
            'experience' => $item['experience'] ?? null,
            'employment_type' => $item['employment_type'] ?? null,
            'status' => $item['status'] ?? null,
            'published_at' => $item['published_at'] ?? null,
            'locality_id' => $item['locality_id'] ?? null,
            'locality_name' => $item['locality_name'] ?? null,
            'direction_title' => $item['direction_title'] ?? null,
            'speciality_title' => $item['speciality_title'] ?? null,
            'latitude' => $item['latitude'] ?? null,
            'longitude' => $item['longitude'] ?? null,
            'url' => 'https://team.rzd.ru/career/vacancies/' . ($item['id'] ?? ''),
        ];
    }

    private function attachDetailsByTitle(array &$rows): void
    {
        foreach ($rows as $index => $row) {
            $title = $row['position_title'];
            if ($title && isset($this->detailByTitle[$title])) {
                $rows[$index]['detail'] = $this->detailByTitle[$title];

                continue;
            }

            $detail = $this->fetchVacancyDetail((int) $row['id']);
            if ($title) {
                $this->detailByTitle[$title] = $detail;
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

        $response = $this->rzhdHttpClient()->get(self::BASE_URL . '/' . $id);

        return $this->detailCache[$id] = $response->ok() ? $response->json() : [];
    }

    /**
     * The API currently serves a certificate chain absent from the runtime CA bundle.
     * Keep the TLS exception scoped to RZD requests until that bundle is updated.
     */
    private function rzhdHttpClient()
    {
        return Http::withoutVerifying()->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Accept' => 'application/json, text/plain, */*',
            'Origin' => 'https://team.rzd.ru',
            'Referer' => 'https://team.rzd.ru/',
        ]);
    }

    private function mapToEntity(array $row): array
    {
        $detail = $row['detail'] ?? [];
        $salary = $this->formatSalary($row);
        $company = $this->buildCompanyFields($detail);
        $addresses = $this->buildAddresses($row, $detail);
        $category = $this->buildCategory($row);

        $entity = [
            'url' => $row['url'],
            'mobile_url' => $row['url'],
            'creation_date' => $this->formatDate($detail['createdAt'] ?? $row['published_at']),
            'update_date' => $this->formatDate($detail['updatedAt'] ?? $row['published_at']),
            'salary' => $salary,
            'currency' => $salary ? 'RUR' : null,
            'category' => $category,
            'job_name' => $row['position_title'],
            'employment' => $this->mapEmployment($row['employment_type']),
            'schedule' => $this->mapSchedule($row['schedule']),
            'description' => $this->composeDescription($detail),
            'duty' => $this->escapeForXml($detail['responsibilities'] ?? null),
            'term' => $this->buildTerm($detail),
            'requirement' => $this->buildRequirement($row, $detail),
            'company_name' => $company['name'],
            'company_description' => $this->escapeForXml($company['description']),
            'company_logo' => $company['logo'],
            'company_site' => $company['site'],
            'company_email' => $company['email'],
            'company_phone' => $company['phone'],
            'company_fax' => $company['fax'],
            'hr_agency' => false,
            'contact_name' => $company['contact_name'],
            'campaign' => $this->composeCampaign($detail),
        ];

        if ($addresses) {
            $entity['addresses'] = $addresses;
        }

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

    private function buildCategory(array $row): array
    {
        $category = [];
        $industry = $row['direction_title'];
        $specialization = $row['speciality_title'];

        if ($industry) {
            $category['industry'] = $industry;
        }

        if ($specialization) {
            $category['specialization'] = $specialization;
        }

        return $category;
    }

    private function buildAddresses(array $row, array $detail): array
    {
        $location = $detail['address'] ?? $row['locality_name'];
        $address = array_filter([
            'location' => $this->escapeForXml($location),
            'metro' => null,
            'lng' => $row['longitude'],
            'lat' => $row['latitude'],
        ]);

        return $address ? [$address] : [];
    }

    private function buildCompanyFields(array $detail): array
    {
        $company = $detail['company'] ?? $detail['organization'] ?? [];

        return [
            'name' => $company['name'] ?? 'РЖД',
            'description' => $company['description'] ?? null,
            'logo' => $company['logo'] ?? null,
            'site' => $company['site'] ?? null,
            'email' => $company['email'] ?? null,
            'phone' => $company['phone'] ?? null,
            'fax' => $company['fax'] ?? null,
            'contact_name' => $company['contactName'] ?? null,
        ];
    }

    private function composeCampaign(array $detail): ?string
    {
        $company = $detail['company'] ?? $detail['organization'] ?? [];
        $companyName = $company['name'] ?? 'РЖД';
        $externalId = $detail['externalId'] ?? null;

        if ($externalId) {
            return trim($companyName . ' ' . $externalId);
        }

        return $companyName;
    }

    private function formatDate(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        try {
            $date = Carbon::parse($value)->setTimezone(self::TIMEZONE);
        } catch (\Throwable $e) {
            return null;
        }

        return $date->format('Y-m-d H:i:s') . ' GMT' . $this->formatOffset($date);
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

    private function formatSalary(array $row): ?string
    {
        $from = $row['salary_from'];
        $to = $row['salary_to'];

        if ($from && $to) {
            return "от {$from} до {$to}";
        }

        if ($from) {
            return "от {$from}";
        }

        if ($to) {
            return "до {$to}";
        }

        return $row['salary_month'] ? "≈{$row['salary_month']}" : null;
    }

    private function mapEmployment(?string $value): ?string
    {
        return self::EMPLOYMENT_LABELS[$value] ?? null;
    }

    private function mapSchedule(?string $value): ?string
    {
        return self::SCHEDULE_LABELS[$value] ?? null;
    }

    private function composeDescription(array $detail): ?string
    {
        $parts = [];
        foreach (['description', 'requirements', 'responsibilities', 'package'] as $field) {
            if (!empty($detail[$field])) {
                $parts[] = $this->escapeForXml($detail[$field]);
            }
        }

        return $parts ? implode("\n", array_unique($parts)) : null;
    }

    private function buildTerm(array $detail): array
    {
        return array_filter([
            'contract' => null,
            'text' => $this->escapeForXml($detail['package'] ?? null),
        ]);
    }

    private function buildRequirement(array $row, array $detail): array
    {
        $experience = $this->mapExperience($row['experience']);

        return array_filter([
            'age' => null,
            'sex' => null,
            'education' => null,
            'experience' => $experience,
            'qualification' => $this->escapeForXml($detail['requirements'] ?? null),
        ]);
    }

    private function mapExperience(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $map = [
            'norequired' => 'Не требуется',
            'short' => 'до 1 года',
            'mid' => '1-3 года',
            'long' => '3 и более',
        ];

        return $map[$value] ?? $value;
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
            $text
        );
    }
}
