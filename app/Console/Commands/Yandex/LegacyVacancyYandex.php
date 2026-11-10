<?php

namespace App\Console\Commands\Yandex;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LegacyVacancyYandex extends Command
{
    private const API_URL = 'https://yandex.ru/jobs/api/publications';
    private const PAGE_SIZE = 20;

    protected $signature = 'app:vacancy-yandex
        {--cursor= : Начальный cursor для пагинации}
        {--max-pages=0 : Максимальное число страниц за запуск (0 = без лимита, парсить все)}
        {--delay-min-ms=2500 : Минимальная задержка между запросами, мс}
        {--delay-max-ms=5500 : Максимальная задержка между запросами, мс}
        {--max-retries=5 : Ретраи для 429/5xx и сетевых ошибок}
        {--output=yandex_vacancies.json : Имя выходного файла в storage/app}';

    protected $description = 'Парсинг вакансий Yandex Jobs API c бережным rate-limit режимом';

    public function handle(): int
    {
        $maxPages = max(0, (int) $this->option('max-pages'));
        $delayMin = max(500, (int) $this->option('delay-min-ms'));
        $delayMax = max($delayMin, (int) $this->option('delay-max-ms'));
        $maxRetries = max(0, (int) $this->option('max-retries'));
        $outputName = (string) $this->option('output');
        $isUnlimited = $maxPages === 0;

        $cursor = $this->normalizeCursor($this->option('cursor'));
        $seenCursors = [];
        $publications = [];
        $pagesFetched = 0;

        $this->info(sprintf(
            'Старт Yandex parser: max-pages=%s, delay=%d-%dms, page_size=%d',
            $isUnlimited ? 'all' : (string) $maxPages,
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

            $publications = array_merge($publications, $results);
            $pagesFetched++;

            $nextCursor = $this->extractCursorFromNext($json['next'] ?? null);

            $this->line(sprintf(
                'Страница %d: получено %d, всего %d%s',
                $pagesFetched,
                count($results),
                count($publications),
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

        $payload = [
            'parsed_at' => now()->toIso8601String(),
            'source' => self::API_URL,
            'page_size' => self::PAGE_SIZE,
            'pages_fetched' => $pagesFetched,
            'total_publications' => count($publications),
            'next_cursor_for_resume' => $cursor,
            'publications' => $publications,
        ];

        $outputPath = storage_path('app/' . ltrim($outputName, '/'));
        file_put_contents(
            $outputPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->info('Готово. Сохранено: ' . $outputPath);

        return self::SUCCESS;
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
                $response = Http::timeout(20)
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
}
