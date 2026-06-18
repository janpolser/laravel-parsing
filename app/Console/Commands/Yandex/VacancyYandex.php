<?php

namespace App\Console\Commands\Yandex;

use App\Services\YandexFeedXmlFormat;
use DOMDocument;
use DOMNode;
use DOMXPath;
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
        {--url= : URL, slug или id конкретной вакансии для проверки одной публикации}
        {--skip-full-description : Не загружать детальные описания, использовать только short_summary из API}
        {--xml-outfile=YandexVacancies : Базовое имя XML-файла (без даты и .xml)}
        {--raw-json=storage/app/vacancies.json : Путь для сохранения raw JSON ответа}';

    protected $description = 'Собирает все вакансии Yandex Jobs API и формирует YVL-совместимый XML';

    public function handle(YandexFeedXmlFormat $xmlFormatter): int
    {
        $maxPages = max(0, (int) $this->option('max-pages'));
        $delayMin = max(500, (int) $this->option('delay-min-ms'));
        $delayMax = max($delayMin, (int) $this->option('delay-max-ms'));
        $maxRetries = max(0, (int) $this->option('max-retries'));
        $loadFullDescription = !$this->option('skip-full-description');
        $xmlOutfile = (string) $this->option('xml-outfile');
        $rawJsonPath = (string) $this->option('raw-json');
        $singlePublicationInput = $this->normalizeSinglePublicationInput($this->option('url'));
        $isUnlimited = $maxPages === 0;

        if ($singlePublicationInput !== null) {
            return $this->handleSinglePublication(
                $xmlFormatter,
                $singlePublicationInput,
                $xmlOutfile,
                $rawJsonPath,
                $loadFullDescription
            );
        }

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
            $entity = $this->mapToEntity($publication, $loadFullDescription);
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

    private function mapToEntity(array $publication, bool $loadFullDescription = true): array
    {
        $jobName = trim((string) ($publication['title'] ?? ''));
        $slug = trim((string) ($publication['publication_slug_url'] ?? ''));
        $summary = $this->sanitizeText($publication['short_summary'] ?? '');
        $url = $this->buildVacancyUrl($publication, $slug);

        if ($jobName === '' || $url === '') {
            return [];
        }

        $description = $loadFullDescription ? $this->composeDescriptionFromPublication($publication) : '';
        if ($description === '' && $loadFullDescription) {
            $description = $this->fetchFullDescription($publication, $url);
        }

        if ($description === '') {
            $description = $summary;
        }

        if ($description === '') {
            $description = 'Актуальная вакансия Яндекса. Подробности по ссылке в карточке.';
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
                    ->withHeaders($this->requestHeaders())
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

    private function handleSinglePublication(
        YandexFeedXmlFormat $xmlFormatter,
        string $input,
        string $xmlOutfile,
        string $rawJsonPath,
        bool $loadFullDescription
    ): int {
        $identifier = $this->publicationIdentifierFromInput($input);
        if ($identifier === '') {
            $this->error('Не удалось извлечь id или slug вакансии из --url.');

            return self::FAILURE;
        }

        $this->info("Проверка одной вакансии Yandex: {$identifier}");

        $publication = $this->requestPublicationDetail($identifier);
        if ($publication === null) {
            $this->error('Не удалось получить detail JSON вакансии.');

            return self::FAILURE;
        }

        $entity = $this->mapToEntity($publication, $loadFullDescription);
        if (empty($entity)) {
            $this->error('Не удалось подготовить вакансию для XML.');

            return self::FAILURE;
        }

        $xmlPath = storage_path('app/public/yandex/' . $xmlOutfile . '-single-' . today()->toDateString() . '.xml');
        $xmlFormatter->createXmlFeed([$entity], self::HOST, $xmlPath);
        $this->persistRawJson($rawJsonPath, [$publication], 1, null);

        $this->info('Описание собрано, длина: ' . mb_strlen($entity['description'] ?? '') . ' символов');
        $this->info("XML сформирован: {$xmlPath}");
        $this->info("Raw JSON сохранен: {$rawJsonPath}");

        return self::SUCCESS;
    }

    private function fetchFullDescription(array $publication, string $url): string
    {
        $description = $this->fetchFullDescriptionFromApi($publication);
        if ($description !== '') {
            return $description;
        }

        return $this->fetchFullDescriptionFromHtml($url);
    }

    private function fetchFullDescriptionFromApi(array $publication): string
    {
        $identifier = $this->publicationDetailIdentifier($publication);
        if ($identifier === '') {
            return '';
        }

        $detail = $this->requestPublicationDetail($identifier);
        if ($detail === null) {
            return '';
        }

        return $this->composeDescriptionFromPublication($detail);
    }

    private function requestPublicationDetail(string $identifier): ?array
    {
        $detailUrl = self::API_URL . '/' . rawurlencode($identifier);

        try {
            $response = Http::timeout(25)
                ->acceptJson()
                ->withHeaders($this->requestHeaders())
                ->get($detailUrl);
        } catch (\Throwable $e) {
            Log::warning('Yandex parser detail API network error', [
                'url' => $detailUrl,
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        if (!$response->successful()) {
            Log::warning('Yandex parser detail API non-200 response', [
                'url' => $detailUrl,
                'status' => $response->status(),
                'body_snippet' => mb_substr($response->body(), 0, 400),
            ]);

            return null;
        }

        $detail = $response->json();
        if (!is_array($detail)) {
            return null;
        }

        return $detail;
    }

    private function fetchFullDescriptionFromHtml(string $url): string
    {
        if (!$this->isYandexVacancyUrl($url)) {
            return '';
        }

        try {
            $response = Http::timeout(25)
                ->withHeaders($this->requestHeaders())
                ->get($url);
        } catch (\Throwable $e) {
            Log::warning('Yandex parser detail page network error', [
                'url' => $url,
                'message' => $e->getMessage(),
            ]);

            return '';
        }

        if (!$response->successful()) {
            Log::warning('Yandex parser detail page non-200 response', [
                'url' => $url,
                'status' => $response->status(),
                'body_snippet' => mb_substr($response->body(), 0, 400),
            ]);

            return '';
        }

        return $this->extractDescriptionFromHtml($response->body());
    }

    private function composeDescriptionFromPublication(array $publication): string
    {
        $fields = [
            'description' => null,
            'duties' => 'Какие задачи вас ждут',
            'tech_stack' => 'Технологии',
            'our_team' => 'О команде',
            'key_qualifications' => 'Мы ждем, что вы',
            'additional_requirements' => 'Будет плюсом',
            'conditions' => 'Условия',
        ];

        $parts = [];
        foreach ($fields as $field => $title) {
            $text = $this->descriptionFieldToText($publication[$field] ?? null);
            if ($text === '') {
                continue;
            }

            $parts[] = $title !== null ? "{$title}:\n{$text}" : $text;
        }

        return $this->normalizeMultilineText(implode("\n\n", $parts));
    }

    private function descriptionFieldToText(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['content'] ?? '';
        }

        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        $text = $this->htmlFragmentToText($value);
        $text = preg_replace('/^\*\s+/mu', '- ', $text) ?? $text;

        return $this->normalizeMultilineText($text);
    }

    private function extractDescriptionFromHtml(string $html): string
    {
        if (trim($html) === '' || $this->containsCaptcha($html)) {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previousLibxmlState = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        if (!$loaded) {
            return '';
        }

        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//main[contains(@class, "Vacancy_vacancy__")]')->item(0) ?? $dom;
        $sections = $xpath->query(
            './/section[contains(concat(" ", normalize-space(@class), " "), " lc-jobs-common-section ")]',
            $root
        );

        if ($sections === false || $sections->length === 0) {
            return '';
        }

        $parts = [];
        foreach ($sections as $section) {
            $content = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " lc-jobs-common-section__content ")]',
                $section
            )->item(0);

            if (!$content instanceof DOMNode) {
                continue;
            }

            $body = $this->htmlNodeToText($content);
            if ($body === '') {
                continue;
            }

            $title = '';
            $titleNode = $xpath->query(
                './/*[contains(concat(" ", normalize-space(@class), " "), " lc-jobs-common-section__section-title ")]',
                $section
            )->item(0);

            if ($titleNode instanceof DOMNode) {
                $title = $this->normalizePlainText($titleNode->textContent);
            }

            $parts[] = $title !== '' ? "{$title}:\n{$body}" : $body;
        }

        return $this->normalizeMultilineText(implode("\n\n", $parts));
    }

    private function htmlNodeToText(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?: '';
        }

        return $this->htmlFragmentToText($html);
    }

    private function htmlFragmentToText(string $html): string
    {
        $html = preg_replace('/<\s*br\s*\/?\s*>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*li\b[^>]*>/iu', "\n- ", $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*li\s*>/iu', "\n", $html) ?? $html;
        $html = preg_replace('/<\s*\/\s*(p|div|section|article|h[1-6]|ul|ol)\s*>/iu', "\n\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $this->normalizeMultilineText($text);
    }

    private function publicationDetailIdentifier(array $publication): string
    {
        $id = $publication['id'] ?? null;
        if (is_int($id) || is_string($id)) {
            $id = trim((string) $id);
            if ($id !== '') {
                return $id;
            }
        }

        $slug = $publication['publication_slug_url'] ?? null;
        if (is_string($slug)) {
            return trim($slug);
        }

        return '';
    }

    private function publicationIdentifierFromInput(string $input): string
    {
        $input = trim($input);
        if ($input === '') {
            return '';
        }

        $path = parse_url($input, PHP_URL_PATH);
        if (is_string($path) && trim($path, '/') !== '') {
            $segments = array_values(array_filter(explode('/', trim($path, '/'))));
            $vacanciesIndex = array_search('vacancies', $segments, true);

            if ($vacanciesIndex !== false && isset($segments[$vacanciesIndex + 1])) {
                return trim($segments[$vacanciesIndex + 1]);
            }

            $lastSegment = end($segments);

            return is_string($lastSegment) ? trim($lastSegment) : '';
        }

        return trim($input, '/');
    }

    private function normalizeSinglePublicationInput(mixed $input): ?string
    {
        if (!is_string($input)) {
            return null;
        }

        $input = trim($input);

        return $input !== '' ? $input : null;
    }

    private function normalizePlainText(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\u{00A0}", "\u{202F}"], ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function normalizeMultilineText(string $text): string
    {
        $text = str_replace(["\xC2\xA0", "\u{00A0}", "\u{202F}"], ' ', $text);
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        $lines = [];
        $previousWasBlank = true;
        foreach (explode("\n", $text) as $line) {
            $line = preg_replace('/[ \t]+/u', ' ', $line) ?? $line;
            $line = trim($line);

            if ($line === '') {
                if (!$previousWasBlank) {
                    $lines[] = '';
                }
                $previousWasBlank = true;
                continue;
            }

            $lines[] = $line;
            $previousWasBlank = false;
        }

        while (!empty($lines) && end($lines) === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    private function containsCaptcha(string $html): bool
    {
        return str_contains($html, 'not a robot')
            || str_contains($html, 'не робот')
            || str_contains($html, 'CheckboxCaptcha')
            || str_contains($html, 'SmartCaptcha');
    }

    private function isYandexVacancyUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);

        return is_string($host)
            && ($host === 'yandex.ru' || str_ends_with($host, '.yandex.ru'))
            && is_string($path)
            && str_starts_with($path, '/jobs/vacancies/');
    }

    private function requestHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7',
            'Referer' => 'https://yandex.ru/jobs/vacancies',
        ];
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
