<?php

namespace App\Console\Commands\Yandex;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VacancyYandex extends Command
{
    private const API_URL = 'https://yandex.ru/jobs/api/publications';
    private const PAGE_SIZE = 20;
    private const HOST = 'yandex.ru';
    private const TIMEZONE = 'Europe/Moscow';

    protected $signature = 'yandex:vacancy-yandex
        {--cursor= : Начальный cursor для пагинации}
        {--max-pages=0 : Максимальное число страниц (0 = без лимита, парсить все)}
        {--delay-min-ms=2500 : Минимальная задержка между запросами страниц, мс}
        {--delay-max-ms=5500 : Максимальная задержка между запросами страниц, мс}
        {--max-retries=5 : Число ретраев для 429/5xx и сетевых ошибок}
        {--xml-outfile=YandexVacancies : Базовое имя XML-файла (без даты и .xml)}
        {--raw-json=storage/app/vacancies.json : Путь для сохранения raw JSON ответа}';

    protected $description = 'Собирает все вакансии Yandex Jobs API и формирует YVL-совместимый XML';

    public function handle(YandexFeedXmlFormat $xmlFormatter): int
    {
        $maxPages = max(0, (int) $this->option('max-pages'));
        $delayMin = max(500, (int) $this->option('delay-min-ms'));
        $delayMax = max($delayMin, (int) $this->option('delay-max-ms'));
        $maxRetries = max(0, (int) $this->option('max-retries'));
        $xmlOutfile = (string) $this->option('xml-outfile');
        $rawJsonPath = (string) $this->option('raw-json');
        $isUnlimited = $maxPages === 0;

        $cursor = $this->normalizeCursor($this->option('cursor'));
        $seenCursors = [];
        $pagesFetched = 0;

        $allPublications = [];

        $this->info(sprintf(
            'Старт Yandex parser: mode=%s, delay=%d-%dms, page_size=%d',
            $isUnlimited ? 'all-pages' : ('max-pages=' . $maxPages),
            $delayMin,
            $delayMax,
            self::PAGE_SIZE
        ));

        while (true) {
            if ($cursor !== null && isset($seenCursors[$cursor])) {
                $this->warn('Остановлено: обнаружен повтор cursor (защита от зацикливания).');
                break;
            }

            if ($cursor !== null) {
                $seenCursors[$cursor] = true;
            }

            $response = $this->requestPage($cursor, $maxRetries);
            if ($response === null) {
                $this->error('Остановлено: не удалось получить страницу после ретраев.');
                return self::FAILURE;
            }

            $json = $response->json();
            if (!is_array($json)) {
                $this->error('Остановлено: API вернул невалидный JSON.');
                return self::FAILURE;
            }

            $results = $json['results'] ?? null;
            if (!is_array($results)) {
                $this->error('Остановлено: в ответе нет массива results.');
                return self::FAILURE;
            }

            $allPublications = array_merge($allPublications, $results);
            $pagesFetched++;

            $nextCursor = $this->extractCursorFromNext($json['next'] ?? null);

            $this->line(sprintf(
                'Страница %d: получено %d, всего %d%s',
                $pagesFetched,
                count($results),
                count($allPublications),
                $nextCursor ? ', next cursor есть' : ', next cursor нет'
            ));

            if ($nextCursor === null) {
                break;
            }

            $cursor = $nextCursor;

            if (!$isUnlimited && $pagesFetched >= $maxPages) {
                $this->warn("Достигнут лимит страниц: {$maxPages}");
                break;
            }

            $this->sleepWithJitter($delayMin, $delayMax);
        }

        if (empty($allPublications)) {
            $this->warn('Список вакансий пуст. XML не сформирован.');
            return self::SUCCESS;
        }

        $entities = [];
        foreach ($allPublications as $publication) {
            $entity = $this->mapToEntity($publication);
            if (!empty($entity)) {
                $entities[] = $entity;
            }
        }

        if (empty($entities)) {
            $this->warn('После нормализации не осталось валидных вакансий для XML.');
            return self::SUCCESS;
        }

        $xmlPath = storage_path('app/public/yandex/' . $xmlOutfile . today()->toDateString() . '.xml');
        $xmlFormatter->createXmlFeed($entities, self::HOST, $xmlPath);

        $this->persistRawJson(
            $rawJsonPath,
            $allPublications,
            $pagesFetched,
            $cursor
        );

        $this->info("XML сформирован: {$xmlPath}");
        $this->info("Raw JSON сохранен: {$rawJsonPath}");

        return self::SUCCESS;
    }

    private function mapToEntity(array $publication): array
    {
        $jobName = trim((string) ($publication['title'] ?? ''));
        $slug = trim((string) ($publication['publication_slug_url'] ?? ''));
        $description = $this->sanitizeText($publication['short_summary'] ?? '');

        if ($description === '') {
            $description = 'Актуальная вакансия Яндекса. Подробности по ссылке в карточке.';
        }

        $url = $this->buildVacancyUrl($publication, $slug);

        if ($jobName === '' || $url === '') {
            return [];
        }

        $vacancy = is_array($publication['vacancy'] ?? null) ? $publication['vacancy'] : [];
        $service = is_array($publication['public_service'] ?? null) ? $publication['public_service'] : [];

        $cityNames = $this->extractCityNames($vacancy['cities'] ?? []);
        $skills = $this->extractNames($vacancy['skills'] ?? []);
        $workModes = $this->extractNames($vacancy['work_modes'] ?? []);

        $companyName = $this->sanitizeText((string) ($service['name'] ?? 'Яндекс'));
        if ($companyName === '') {
            $companyName = 'Яндекс';
        }

        $publishedAt = $this->formatPublishedDate($publication['published_at'] ?? null);

        $category = [];
        if ($companyName !== '') {
            $category['industry'] = $companyName;
        }
        if (!empty($skills)) {
            $category['specialization'] = implode(', ', $skills);
        }

        $addresses = [];
        foreach ($cityNames as $cityName) {
            $addresses[] = ['location' => $cityName];
        }

        $campaignParts = [];
        if (!empty($skills)) {
            $campaignParts[] = 'Навыки: ' . implode(', ', $skills);
        }
        if (!empty($workModes)) {
            $campaignParts[] = 'Формат: ' . implode(', ', $workModes);
        }
        $campaign = empty($campaignParts) ? null : implode(' | ', $campaignParts);

        $entity = [
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => $publishedAt,
            'update_date' => $publishedAt,
            'category' => $category,
            'job_name' => $jobName,
            'schedule' => !empty($workModes) ? implode(', ', $workModes) : null,
            'description' => $description,
            'addresses' => !empty($addresses) ? $addresses : null,
            'company_name' => $companyName,
            'company_description' => $this->sanitizeText((string) ($service['description'] ?? '')),
            'company_logo' => isset($service['icon']) ? (string) $service['icon'] : null,
            'company_site' => 'https://yandex.ru/jobs/vacancies',
            'hr_agency' => false,
            'campaign' => $campaign,
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

    private function buildVacancyUrl(array $publication, string $slug): string
    {
        $redirectUrl = $publication['redirect_url'] ?? null;
        if (is_string($redirectUrl) && trim($redirectUrl) !== '') {
            return trim($redirectUrl);
        }

        if ($slug !== '') {
            return 'https://yandex.ru/jobs/vacancies/' . ltrim($slug, '/');
        }

        $id = $publication['id'] ?? null;
        if ($id) {
            return 'https://yandex.ru/jobs/vacancies';
        }

        return '';
    }

    private function requestPage(?string $cursor, int $maxRetries)
    {
        $query = ['page_size' => self::PAGE_SIZE];
        if ($cursor !== null) {
            $query['cursor'] = $cursor;
        }

        $attempt = 0;

        while ($attempt <= $maxRetries) {
            $attempt++;

            try {
                $response = Http::timeout(25)
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                        'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
                        'Referer' => 'https://yandex.ru/jobs/vacancies',
                    ])
                    ->get(self::API_URL, $query);
            } catch (\Throwable $e) {
                Log::warning('Yandex parser network error', [
                    'cursor' => $cursor,
                    'attempt' => $attempt,
                    'message' => $e->getMessage(),
                ]);

                if ($attempt > $maxRetries) {
                    return null;
                }

                $this->backoff($attempt, null);
                continue;
            }

            $status = $response->status();

            if ($response->successful()) {
                return $response;
            }

            Log::warning('Yandex parser non-200 response', [
                'cursor' => $cursor,
                'attempt' => $attempt,
                'status' => $status,
                'body_snippet' => mb_substr($response->body(), 0, 400),
            ]);

            if (!$this->shouldRetryStatus($status) || $attempt > $maxRetries) {
                return null;
            }

            $retryAfter = (int) $response->header('Retry-After', 0);
            $this->backoff($attempt, $retryAfter > 0 ? $retryAfter : null);
        }

        return null;
    }

    private function persistRawJson(string $rawJsonPath, array $publications, int $pagesFetched, ?string $nextCursor): void
    {
        $dir = dirname($rawJsonPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $payload = [
            'parsed_at' => Carbon::now(self::TIMEZONE)->toIso8601String(),
            'source' => self::API_URL,
            'page_size' => self::PAGE_SIZE,
            'pages_fetched' => $pagesFetched,
            'total_publications' => count($publications),
            'next_cursor_for_resume' => $nextCursor,
            'results' => $publications,
        ];

        file_put_contents(
            $rawJsonPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function shouldRetryStatus(int $status): bool
    {
        return $status === 429 || ($status >= 500 && $status <= 599);
    }

    private function backoff(int $attempt, ?int $retryAfterSeconds): void
    {
        if ($retryAfterSeconds !== null) {
            $sleepMs = max(1000, $retryAfterSeconds * 1000);
        } else {
            $baseMs = 2000;
            $maxMs = 30000;
            $sleepMs = min($maxMs, (int) ($baseMs * (2 ** ($attempt - 1))));
            $sleepMs += random_int(200, 1200);
        }

        $this->warn("Retry через {$sleepMs}ms");
        usleep($sleepMs * 1000);
    }

    private function sleepWithJitter(int $minMs, int $maxMs): void
    {
        $sleepMs = random_int($minMs, $maxMs);
        usleep($sleepMs * 1000);
    }

    private function extractCursorFromNext(mixed $next): ?string
    {
        if (!is_string($next) || $next === '') {
            return null;
        }

        $query = parse_url($next, PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $params);
        $cursor = $params['cursor'] ?? null;

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function normalizeCursor(mixed $cursor): ?string
    {
        if (!is_string($cursor)) {
            return null;
        }

        $cursor = trim($cursor);

        return $cursor !== '' ? $cursor : null;
    }

    private function sanitizeText(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function extractNames(mixed $collection): array
    {
        if (!is_array($collection)) {
            return [];
        }

        $names = [];
        foreach ($collection as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $names[] = $this->sanitizeText($name);
            }
        }

        return array_values(array_unique($names));
    }

    private function extractCityNames(mixed $cities): array
    {
        return $this->extractNames($cities);
    }

    private function formatPublishedDate(mixed $publishedAt): string
    {
        if (is_string($publishedAt) && trim($publishedAt) !== '') {
            try {
                return Carbon::parse($publishedAt, self::TIMEZONE)
                    ->setTimezone(self::TIMEZONE)
                    ->format('Y-m-d H:i:s') . ' GMT+3';
            } catch (\Throwable) {
                // fallback ниже
            }
        }

        return Carbon::now(self::TIMEZONE)->format('Y-m-d H:i:s') . ' GMT+3';
    }
}
