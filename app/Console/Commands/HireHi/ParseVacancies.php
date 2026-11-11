<?php

namespace App\Console\Commands\HireHi;

use App\Services\YandexFeedXmlFormat;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ParseVacancies extends Command
{
    protected $signature = 'hirehi:parse-vacancies 
                            {--category=development : Категория вакансий}
                            {--search= : Поисковый запрос}
                            {--max-pages=0 : Максимум страниц (0 - все)}
                            {--start-page=1 : Первая страница для выборочного обновления}
                            {--end-page=0 : Последняя страница для выборочного обновления (0 - до конца)}
                            {--request-delay-min-ms=4000 : Минимальная пауза между запросами к HireHi API, мс}
                            {--request-delay-max-ms=10000 : Максимальная пауза между запросами к HireHi API, мс}
                            {--detail-retry-passes=2 : Дополнительных проходов по неудачным карточкам}
                            {--max-retries=5 : Повторов для 429 и временных ошибок API}
                            {--retry-delay-seconds=60 : Начальная пауза перед повтором, секунд}
                            {--proxy=* : Optional HTTP/SOCKS proxy URL, can be repeated}';

    protected $description = 'Парсинг вакансий с hirehi.ru и генерация XML-фида';

    protected array $categoryMap = [
        'development' => 'development',
        'design' => 'design',
        'marketing' => 'marketing',
        'management' => 'management',
        'analytics' => 'analytics',
        'devops' => 'devops',
        'testing' => 'testing',
        'hr' => 'hr',
        'sales' => 'sales',
        'support' => 'support',
        'content' => 'content',
        'administration' => 'administration',
    ];

    protected array $translitMap = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'yo',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ' ' => '-', '+' => '-plus-', '&' => '-and-', '@' => '-at-', '#' => '-hash-',
        '%' => '-percent-', '$' => '-dollar-', '!' => '', '?' => '', ',' => '-',
        '.' => '-', ':' => '-', ';' => '-', '/' => '-', '\\' => '-', '|' => '-',
        '(' => '', ')' => '', '[' => '', ']' => '', '{' => '', '}' => '',
    ];

    private int $requestDelayMinMs = 2000;

    private int $requestDelayMaxMs = 4000;

    private int $maxRetries = 5;

    private int $retryDelaySeconds = 60;

    private ?float $lastRequestAt = null;

    private array $proxies = [];

    private int $proxyCursor = 0;

    private string $lastDetailFailure = '';

    public function handle(YandexFeedXmlFormat $xml)
    {
        $perPage = 100;
        $category = $this->option('category');
        $search = $this->option('search');
        $maxPages = (int) $this->option('max-pages');
        $startPage = max(1, (int) $this->option('start-page'));
        $endPage = (int) $this->option('end-page');
        $timeout = 60;

        $this->requestDelayMinMs = max(2000, (int) $this->option('request-delay-min-ms'));
        $this->requestDelayMaxMs = max(
            $this->requestDelayMinMs,
            (int) $this->option('request-delay-max-ms')
        );
        $this->maxRetries = max(0, (int) $this->option('max-retries'));
        $this->retryDelaySeconds = max(5, (int) $this->option('retry-delay-seconds'));
        $this->proxies = $this->resolveProxies();
        $this->proxyCursor = 0;

        if ($this->isDisabledAfter403()) {
            $this->error('HireHi отключён после HTTP 403. API-запросы не выполняются.');

            return Command::FAILURE;
        }

        $pauseUntil = $this->readPauseUntil();
        if ($pauseUntil !== null && $pauseUntil > time()) {
            $this->warn('HireHi поставлен на паузу до ' . date('c', $pauseUntil) . '. Запросы не выполняются.');

            return Command::FAILURE;
        }

        if (is_file($this->pausePath()) && !is_file($this->probeCompletedPath())) {
            $this->probeAllProxies();
            file_put_contents($this->probeCompletedPath(), date('c') . PHP_EOL, LOCK_EX);

            return Command::FAILURE;
        }

        if (is_file($this->pausePath())) {
            $this->error('Контроль HireHi уже выполнен. API-запросы не выполняются.');

            return Command::FAILURE;
        }

        date_default_timezone_set('Europe/Moscow');
        ini_set('memory_limit', '8G');

        $categorySlug = $this->categoryMap[$category] ?? $this->slugify($category);

        try {
            $key = hash('sha256', json_encode([$category, $search, $maxPages, $startPage, $endPage], JSON_THROW_ON_ERROR));
            $checkpointDir = storage_path('app/private/hirehi-checkpoints/' . $key);
            if (!is_dir($checkpointDir) && !mkdir($checkpointDir, 0700, true) && !is_dir($checkpointDir)) {
                throw new \RuntimeException('Не удалось создать каталог состояния HireHi');
            }
            $lock = fopen($checkpointDir . '/lock', 'c');
            if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Сбор с такими параметрами уже выполняется');
            }
            $snapshotPath = $checkpointDir . '/snapshot.json';
            $completedPath = $checkpointDir . '/completed';
            if (is_file($completedPath)) {
                foreach (glob($checkpointDir . '/detail-*.json') ?: [] as $file) {
                    if (!unlink($file)) {
                        throw new \RuntimeException('Не удалось очистить прежнее состояние HireHi');
                    }
                }
                if (is_file($snapshotPath) && !unlink($snapshotPath)) {
                    throw new \RuntimeException('Не удалось очистить прежний список HireHi');
                }
                if (!unlink($completedPath)) {
                    throw new \RuntimeException('Не удалось очистить отметку завершения HireHi');
                }
            }

            if (is_file($snapshotPath)) {
                $snapshot = json_decode(file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);
                $vacanciesById = $snapshot['vacancies'];
                $duplicateCount = $snapshot['duplicates'];
                $this->info('Продолжаем сохранённый сбор HireHi.');
            } else {
                $baseUrl = 'https://hirehi.ru/api/search/jobs';

                // Первый запрос с include_counts=true
                $queryParams = [
                    'page' => 1,
                    'limit' => $perPage,
                    'sort' => 'date',
                    'category' => $category,
                    'include_counts' => 'true',
                ];

                if ($search) {
                    $queryParams['search'] = $search;
                }

                $this->info('Начинаем парсинг вакансий...');
                $this->info("Категория: {$category}");
                if ($search) {
                    $this->info("Поиск: {$search}");
                }
                $this->info('Маршрутов HireHi: ' . (empty($this->proxies) ? 'direct' : count($this->proxies)));

                $response = $this->requestHireHi($baseUrl, $queryParams, $timeout);

                if ($response === null || $response->failed()) {
                    $this->error('Ошибка при получении данных: ' . ($response?->status() ?? 'network error'));

                    return Command::FAILURE;
                }

                $data = $response->json();

                // Подсчитываем общее количество через filter_counts.format или initial_filter_counts.format
                $formatCounts = $data['filter_counts']['format'] ?? $data['initial_filter_counts']['format'] ?? [];
                $totalCount = 0;

                if (!empty($formatCounts)) {
                    $hybridCount = (int) ($formatCounts['гибрид'] ?? 0);
                    $officeCount = (int) ($formatCounts['офис'] ?? 0);
                    $remoteCount = (int) ($formatCounts['удалённо'] ?? 0);
                    $remoteRussiaCount = (int) ($formatCounts['удалённо по РФ'] ?? 0);
                    $totalCount = $hybridCount + $officeCount + $remoteCount + $remoteRussiaCount;

                    $this->info("Каунты: гибрид({$hybridCount}) + офис({$officeCount}) + удалённо({$remoteCount}) + удалённо_по_рф({$remoteRussiaCount}) = {$totalCount}");
                }

                if ($totalCount === 0) {
                    $this->error('Нет вакансий для обработки');

                    return Command::FAILURE;
                }

                $totalPages = (int) ceil($totalCount / $perPage);

                // Если задан лимит страниц
                if ($maxPages > 0 && $totalPages > $maxPages) {
                    $totalPages = $maxPages;
                    $this->info("Ограничение страниц до: {$totalPages}");
                } else {
                    $this->info("Всего страниц для обработки: {$totalPages}");
                }

                if ($endPage > 0) {
                    $totalPages = min($totalPages, $endPage);
                }
                if ($startPage > $totalPages) {
                    $this->error('Первая страница выходит за пределы доступного диапазона');

                    return Command::FAILURE;
                }

                $vacanciesById = [];
                $duplicateCount = 0;

                $progressBar = $this->output->createProgressBar($totalPages - $startPage + 1);
                $progressBar->start();

                // Для последующих запросов отключаем include_counts
                $queryParams['include_counts'] = 'false';

                for ($i = $startPage; $i <= $totalPages; $i++) {
                    try {
                        $queryParams['page'] = $i;

                        $response = $this->requestHireHi($baseUrl, $queryParams, $timeout);

                        if ($response === null || $response->failed()) {
                            throw new \RuntimeException("Ошибка при запросе страницы $i: " . ($response?->status() ?? 'network error'));
                        }

                        $pageData = $response->json();

                        // В ответе поле 'jobs', а не 'items'
                        if (!isset($pageData['jobs']) || empty($pageData['jobs'])) {
                            throw new \RuntimeException("Нет данных на странице $i");
                        }

                        foreach ($pageData['jobs'] as $vacancy) {
                            $vacancyId = $vacancy['id'] ?? null;
                            $vacancyName = $vacancy['title'] ?? 'Без названия';

                            if (!$vacancyId) {
                                continue;
                            }

                            $vacancyId = (string) $vacancyId;
                            if (isset($vacanciesById[$vacancyId])) {
                                $duplicateCount++;

                                continue;
                            }

                            $vacanciesById[$vacancyId] = $vacancy;
                        }

                    } catch (ConnectionException $e) {
                        throw new \RuntimeException("Таймаут на странице $i: " . $e->getMessage(), 0, $e);
                    } catch (\Exception $e) {
                        throw new \RuntimeException("Ошибка на странице $i: " . $e->getMessage(), 0, $e);
                    }

                    $progressBar->advance();
                }

                $progressBar->finish();
                $this->newLine(2);

                if (empty($vacanciesById)) {
                    $this->error('Не удалось собрать ни одной вакансии');

                    return Command::FAILURE;
                }

                $this->writeCheckpoint($snapshotPath, ['vacancies' => $vacanciesById, 'duplicates' => $duplicateCount]);
            }

            $this->info('Список зафиксирован, уникальных вакансий: ' . count($vacanciesById));

            $editedVacancies = [];
            $detailPasses = max(0, (int) $this->option('detail-retry-passes')) + 1;
            for ($pass = 0; $pass < $detailPasses; $pass++) {
                foreach ($vacanciesById as $vacancyId => $vacancy) {
                    $detailPath = $checkpointDir . '/detail-' . hash('sha256', (string) $vacancyId) . '.json';
                    $detail = is_file($detailPath)
                        ? json_decode(file_get_contents($detailPath), true, 512, JSON_THROW_ON_ERROR)
                        : null;
                    if (($detail['status'] ?? null) === 'success') {
                        $editedVacancies[$vacancyId] = $detail['vacancy'];

                        continue;
                    }
                    if (($detail['attempts'] ?? 0) > $pass) {
                        continue;
                    }
                    $vacancyName = $vacancy['title'] ?? 'Без названия';

                    // Формируем URL
                    $nameSlug = $this->slugify($vacancyName);
                    $vacancyUrl = "https://hirehi.ru/{$categorySlug}/{$nameSlug}-{$vacancyId}";

                    // Парсим дату создания
                    $creationDate = $this->parseDate($vacancy['created_at'] ?? null);

                    $description = $this->getVacancyDescription($vacancyId);
                    if ($description === null || trim($description) === '') {
                        $this->writeCheckpoint($detailPath, [
                            'id' => (string) $vacancyId,
                            'status' => 'failed',
                            'attempts' => $pass + 1,
                            'reason' => $this->lastDetailFailure ?: 'empty description',
                        ]);

                        continue;
                    }

                    // Замена NDA на "Анонимный Работодатель"
                    $companyName = $vacancy['company'] ?? 'HireHi';
                    $companyName = $this->anonymizeCompany($companyName);

                    $editedVacancy = [
                        'url' => $vacancyUrl,
                        'mobile_url' => $vacancyUrl,
                        'creation_date' => $creationDate,
                        'job_name' => $vacancyName,
                        'description' => $description,
                        'company_name' => $companyName,
                        'hr_agency' => 'false',
                        'category' => ['industry' => $category],
                    ];

                    // Зарплата - форматируем в читаемый вид
                    $salaryStr = $vacancy['salary'] ?? '';
                    $editedVacancy['salary'] = $this->formatSalary($salaryStr);
                    $editedVacancy['currency'] = 'RUB';

                    // Формат работы (строка типа "офис Ереван", "удалённо", "гибрид Алматы")
                    $workFormat = $vacancy['format'] ?? '';
                    $editedVacancy['employment'] = $this->detectEmployment($workFormat);
                    $editedVacancy['schedule'] = $workFormat;

                    // Уровень (junior/middle/senior/lead)
                    if (!empty($vacancy['level'])) {
                        $editedVacancy['experience'] = $this->mapExperience($vacancy['level']);
                    }

                    // Адрес берем из поля format (там "тип город")
                    $location = $this->extractLocation($workFormat);
                    $editedVacancy['addresses']['address']['location'] = $location;

                    $this->writeCheckpoint($detailPath, [
                        'id' => (string) $vacancyId,
                        'status' => 'success',
                        'attempts' => $pass + 1,
                        'vacancy' => $editedVacancy,
                    ]);
                    $editedVacancies[$vacancyId] = $editedVacancy;
                }
            }
            $failedCount = count($vacanciesById) - count($editedVacancies);
            $this->warn("Пропущено недоступных карточек: {$failedCount}. Состояние: {$checkpointDir}");

            $editedVacancies = array_values($editedVacancies);
            $processedCount = count($editedVacancies);

            // Генерация XML
            $filename = 'hirehi_' . $category . '_' . now()->format('Y-m-d_H-i') . '.xml';
            $xmlPath = 'storage/app/public/hirehi/' . $filename;

            $xml->createXmlFeed(
                $editedVacancies,
                'https://hirehi.ru/',
                $xmlPath
            );

            $failedDetails = [];
            foreach (glob($checkpointDir . '/detail-*.json') ?: [] as $detailFile) {
                $detail = json_decode(file_get_contents($detailFile), true, 512, JSON_THROW_ON_ERROR);
                if ($detail['status'] === 'failed') {
                    $failedDetails[] = $detail;
                }
            }
            $this->writeCheckpoint($checkpointDir . '/report-' . bin2hex(random_bytes(8)) . '.json', [
                'completed_at' => date('c'),
                'failed' => $failedDetails,
            ]);
            $this->writeCheckpoint($completedPath, ['completed_at' => date('c'), 'failed' => $failedCount]);
            $this->info("✅ Готово! Обработано вакансий: {$processedCount}");
            $this->info("Пропущено дублей по ID: {$duplicateCount}");
            $this->info("💾 Файл сохранён: {$xmlPath}");

        } catch (ConnectionException $e) {
            $this->error('Таймаут при подключении к API: ' . $e->getMessage());

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Неожиданная ошибка: ' . $e->getMessage());
            $this->error($e->getTraceAsString());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Получить детальное описание вакансии по ID
     */
    private function getVacancyDescription($vacancyId): ?string
    {
        $response = $this->requestHireHi("https://hirehi.ru/api/jobs/{$vacancyId}", [], 30);

        if ($response === null || $response->failed()) {
            $this->lastDetailFailure = $response === null ? 'network error' : 'HTTP ' . $response->status();

            return null;
        }
        $this->lastDetailFailure = '';

        $data = $response->json();

        // Собираем описание из детальных полей
        $parts = [];

        if (!empty($data['description_details'])) {
            $parts[] = $this->cleanupHtml($data['description_details']);
        } elseif (!empty($data['description'])) {
            $parts[] = $this->cleanupHtml($data['description']);
        }

        if (!empty($data['requirements_details'])) {
            $parts[] = '<strong>Требования:</strong><br>' . $this->cleanupHtml($data['requirements_details']);
        }

        if (!empty($data['conditions_details'])) {
            $parts[] = '<strong>Условия:</strong><br>' . $this->cleanupHtml($data['conditions_details']);
        }

        if (!empty($data['responsibilities_details'])) {
            $parts[] = '<strong>Обязанности:</strong><br>' . $this->cleanupHtml($data['responsibilities_details']);
        }

        return implode('<br><br>', $parts);
    }

    private function requestHireHi(string $url, array $query = [], int $timeout = 60): ?Response
    {
        $lastResponse = null;
        $attempts = max($this->maxRetries + 1, count($this->proxies));

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $this->waitForRequestSlot();
            $proxy = $this->nextProxy();

            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withOptions($proxy === null ? [] : ['proxy' => $proxy])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; vacancy-feed/1.0)',
                        'Referer' => 'https://hirehi.ru/',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->get($url, $query);
            } catch (ConnectionException $exception) {
                if ($attempt === $attempts - 1) {
                    $this->warn('HireHi network error: ' . $exception->getMessage());

                    return null;
                }

                $this->waitBeforeRetry(null, $attempt + 1, 'network error via ' . ($this->maskProxy($proxy) ?? 'direct'));

                continue;
            }

            $lastResponse = $response;

            if ($response->status() === 403) {
                $this->disableAfter403();
                throw new \RuntimeException('HireHi вернул HTTP 403. Останавливаем команду без повторных запросов.');
            }

            $retryableStatus = in_array($response->status(), [429, 500, 502, 503, 504], true)
                || ($this->proxies !== [] && in_array($response->status(), [401, 403, 407], true));
            if ($response->successful() || !$retryableStatus) {
                return $response;
            }

            if ($attempt === $attempts - 1) {
                return $response;
            }

            $this->waitBeforeRetry(
                $response,
                $attempt + 1,
                'HTTP ' . $response->status() . ' via ' . ($this->maskProxy($proxy) ?? 'direct')
            );
        }

        return $lastResponse;
    }

    private function writeCheckpoint(string $path, array $data): void
    {
        $temporary = $path . '.tmp';
        $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            throw new \RuntimeException('Не удалось сохранить состояние HireHi: ' . $path);
        }
    }

    private function isDisabledAfter403(): bool
    {
        return is_file(storage_path('app/private/hirehi.disabled'));
    }

    private function pausePath(): string
    {
        return storage_path('app/private/hirehi.pause_until');
    }

    private function probeCompletedPath(): string
    {
        return storage_path('app/private/hirehi.probe_completed');
    }

    private function probeAllProxies(): void
    {
        $url = 'https://hirehi.ru/api/search/jobs';
        $query = [
            'page' => 1,
            'limit' => 1,
            'sort' => 'date',
            'category' => 'development',
            'include_counts' => 'true',
        ];

        if ($this->proxies === []) {
            $this->error('HireHi probe: прокси не настроены, прямой запрос запрещён.');

            return;
        }

        foreach ($this->proxies as $proxy) {
            try {
                $response = Http::timeout(20)
                    ->acceptJson()
                    ->withOptions(['proxy' => $proxy])
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; vacancy-feed/1.0)',
                        'Referer' => 'https://hirehi.ru/',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->get($url, $query);

                $this->warn('HireHi probe ' . ($this->maskProxy($proxy) ?? 'proxy') . ': HTTP ' . $response->status());
            } catch (ConnectionException $exception) {
                $this->warn('HireHi probe ' . ($this->maskProxy($proxy) ?? 'proxy') . ': network error');
            }
        }
    }

    private function disableAfter403(): void
    {
        $path = storage_path('app/private/hirehi.disabled');
        @mkdir(dirname($path), 0750, true);
        file_put_contents($path, date('c') . PHP_EOL, LOCK_EX);
    }

    private function readPauseUntil(): ?int
    {
        $path = storage_path('app/private/hirehi.pause_until');
        if (!is_file($path)) {
            return null;
        }

        $value = trim((string) file_get_contents($path));
        if ($value === '' || !ctype_digit($value)) {
            return null;
        }

        $timestamp = (int) $value;

        return $timestamp > time() ? $timestamp : null;
    }

    private function resolveProxies(): array
    {
        $configured = [];
        $optionProxies = $this->option('proxy');
        if (is_array($optionProxies)) {
            $configured = array_merge($configured, $optionProxies);
        } elseif (is_string($optionProxies) && trim($optionProxies) !== '') {
            $configured[] = $optionProxies;
        }
        $configured = array_merge($configured, $this->parseProxyList($this->environmentValue('HIREHI_PROXIES')));

        $proxies = [];
        foreach ($configured as $proxy) {
            if (!is_string($proxy) || trim($proxy) === '') {
                continue;
            }
            $proxy = trim($proxy);
            $scheme = parse_url($proxy, PHP_URL_SCHEME);
            if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https', 'socks5', 'socks5h'], true)) {
                $this->warn('Skipping unsupported HireHi proxy: ' . $this->maskProxy($proxy));

                continue;
            }
            $proxies[$proxy] = $proxy;
        }

        return array_values($proxies);
    }

    private function parseProxyList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return preg_split('/[\r\n,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function environmentValue(string $key): ?string
    {
        foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function nextProxy(): ?string
    {
        if ($this->proxies === []) {
            return null;
        }

        $proxy = $this->proxies[$this->proxyCursor % count($this->proxies)];
        $this->proxyCursor++;

        return $proxy;
    }

    private function maskProxy(?string $proxy): ?string
    {
        if ($proxy === null) {
            return null;
        }
        $parts = parse_url($proxy);
        if (!is_array($parts) || empty($parts['host'])) {
            return '[proxy hidden]';
        }
        $scheme = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : 'proxy';
        $auth = isset($parts['user']) ? '***:***@' : '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return "{$scheme}://{$auth}{$parts['host']}{$port}";
    }

    private function waitForRequestSlot(): void
    {
        if ($this->lastRequestAt !== null) {
            $delayMs = random_int($this->requestDelayMinMs, $this->requestDelayMaxMs);
            $waitMicroseconds = (int) (($delayMs / 1000 - (microtime(true) - $this->lastRequestAt)) * 1_000_000);

            if ($waitMicroseconds > 0) {
                usleep($waitMicroseconds);
            }
        }

        $this->lastRequestAt = microtime(true);
    }

    private function waitBeforeRetry(?Response $response, int $attempt, string $reason): void
    {
        $retryAfter = $response?->header('Retry-After');
        $seconds = is_numeric($retryAfter)
            ? (int) $retryAfter
            : min($this->retryDelaySeconds * (2 ** ($attempt - 1)), 600);
        $seconds = max(5, min($seconds, 900));

        $this->warn("HireHi {$reason}; retry {$attempt}/{$this->maxRetries} in {$seconds}s.");
        sleep($seconds);
    }

    /**
     * Парсинг даты из формата API
     */
    private function parseDate(?string $dateStr): string
    {
        if (empty($dateStr)) {
            return now()->format('Y-m-d H:i:s') . ' GMT+3';
        }

        try {
            $date = new DateTime($dateStr);
            $date->setTimezone(new \DateTimeZone('Europe/Moscow'));

            return $date->format('Y-m-d H:i:s') . ' GMT+3';
        } catch (\Exception $e) {
            return now()->format('Y-m-d H:i:s') . ' GMT+3';
        }
    }

    /**
     * Форматирование зарплаты в читаемый вид
     * "~ от 74 100 ₽" → "от 74 100 рублей"
     */
    private function formatSalary(string $salaryStr): string
    {
        if (empty($salaryStr)) {
            return '';
        }

        // Заменяем символы
        $formatted = str_replace(['₽', '~'], ['', ''], $salaryStr);
        $formatted = trim($formatted);

        // Заменяем "₽" на "рублей" в конце строки
        $formatted = preg_replace('/\s*$/', ' рублей', $formatted);

        return $formatted;
    }

    /**
     * Замена NDA на "Анонимный Работодатель"
     */
    private function anonymizeCompany(string $companyName): string
    {
        $companyName = trim($companyName);

        // Различные варианты NDA (в любом регистре)
        $ndaVariants = ['nda', 'NDA', 'НДА', 'нда', 'Nda', 'N.D.A.', 'N.D.A'];

        foreach ($ndaVariants as $nda) {
            if (strcasecmp($companyName, $nda) === 0) {
                return 'Анонимный Работодатель';
            }
        }

        return $companyName;
    }

    /**
     * Определение типа занятости по формату
     */
    private function detectEmployment(string $format): string
    {
        $format = mb_strtolower($format);

        if (str_contains($format, 'удалённо')) {
            return 'remote';
        }

        if (str_contains($format, 'офис')) {
            return 'office';
        }

        if (str_contains($format, 'гибрид')) {
            return 'hybrid';
        }

        return 'full'; // По умолчанию
    }

    /**
     * Извлечение локации из строки формата
     */
    private function extractLocation(string $format): string
    {
        // Формат: "гибрид Ереван", "офис Москва", "удалённо"
        $parts = explode(' ', $format, 2);

        if (count($parts) > 1) {
            return $parts[1]; // Город
        }

        return $format === 'удалённо' ? 'Удаленно' : $format;
    }

    /**
     * Маппинг уровня опыта
     */
    private function mapExperience(string $level): string
    {
        $map = [
            'intern' => 'noExperience',
            'junior' => 'between1And3',
            'middle' => 'between1And3',
            'senior' => 'between3And6',
            'lead' => 'moreThan6',
            'head' => 'moreThan6',
        ];

        return $map[strtolower($level)] ?? 'between1And3';
    }

    /**
     * Очистка HTML
     */
    private function cleanupHtml(string $html): string
    {
        $text = strip_tags($html, '<p><br><ul><ol><li><strong><b><em><i>');
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\xC2\xA0", ' ', $text);

        return preg_replace('/\s+/', ' ', $text);
    }

    private function slugify(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $result = '';

        for ($i = 0; $i < mb_strlen($text); $i++) {
            $char = mb_substr($text, $i, 1);
            $result .= $this->translitMap[$char] ?? $char;
        }

        return trim(preg_replace('/-+/', '-', $result), '-');
    }
}
