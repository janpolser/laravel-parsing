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
                            {--request-delay-ms=1500 : Минимальная пауза между запросами к HireHi API, мс}
                            {--max-retries=5 : Повторов для 429 и временных ошибок API}
                            {--retry-delay-seconds=60 : Начальная пауза перед повтором, секунд}';

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

    private int $requestDelayMs = 1500;
    private int $maxRetries = 5;
    private int $retryDelaySeconds = 60;
    private ?float $lastRequestAt = null;

    public function handle(YandexFeedXmlFormat $xml)
    {
        $perPage = 100;
        $category = $this->option('category');
        $search = $this->option('search');
        $maxPages = (int) $this->option('max-pages');
        $timeout = 60;

        $this->requestDelayMs = max(250, (int) $this->option('request-delay-ms'));
        $this->maxRetries = max(0, (int) $this->option('max-retries'));
        $this->retryDelaySeconds = max(5, (int) $this->option('retry-delay-seconds'));

        date_default_timezone_set('Europe/Moscow');
        ini_set('memory_limit', '8G');

        $categorySlug = $this->categoryMap[$category] ?? $this->slugify($category);

        try {
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

            $this->info("Начинаем парсинг вакансий...");
            $this->info("Категория: {$category}");
            if ($search) {
                $this->info("Поиск: {$search}");
            }

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
                $totalCount = ($formatCounts['гибрид'] ?? 0) 
                            + ($formatCounts['офис'] ?? 0) 
                            + ($formatCounts['удалённо'] ?? 0) 
                            + ($formatCounts['удалённо по РФ'] ?? 0);
                
                $this->info("Каунты: гибрид({$formatCounts['гибрид']}) + офис({$formatCounts['офис']}) + удалённо({$formatCounts['удалённо']}) + удалённо_по_рф({$formatCounts['удалённо по РФ']}) = {$totalCount}");
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

            $editedVacancies = [];
            $processedCount = 0;
            $descriptionCache = [];

            $progressBar = $this->output->createProgressBar($totalPages);
            $progressBar->start();

            // Для последующих запросов отключаем include_counts
            $queryParams['include_counts'] = 'false';

            for ($i = 1; $i <= $totalPages; $i++) {
                try {
                    $queryParams['page'] = $i;
                    
                    $response = $this->requestHireHi($baseUrl, $queryParams, $timeout);

                    if ($response === null || $response->failed()) {
                        $this->warn("Ошибка при запросе страницы $i: " . ($response?->status() ?? 'network error'));
                        $progressBar->advance();
                        continue;
                    }

                    $pageData = $response->json();

                    // В ответе поле 'jobs', а не 'items'
                    if (!isset($pageData['jobs']) || empty($pageData['jobs'])) {
                        $this->warn("Нет данных на странице $i");
                        $progressBar->advance();
                        continue;
                    }

                    foreach ($pageData['jobs'] as $vacancy) {
                        $vacancyId = $vacancy['id'] ?? null;
                        $vacancyName = $vacancy['title'] ?? 'Без названия';
                        
                        if (!$vacancyId) {
                            continue;
                        }

                        // Формируем URL
                        $nameSlug = $this->slugify($vacancyName);
                        $vacancyUrl = "https://hirehi.ru/{$categorySlug}/{$nameSlug}-{$vacancyId}";

                        // Парсим дату создания
                        $creationDate = $this->parseDate($vacancy['created_at'] ?? null);

                        // Получаем описание (с кэшированием по названию)
                        $cacheKey = $vacancyName;
                        if (isset($descriptionCache[$cacheKey])) {
                            $description = $descriptionCache[$cacheKey];
                        } else {
                            $description = $this->getVacancyDescription($vacancyId);
                            $descriptionCache[$cacheKey] = $description;
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

                        $editedVacancies[] = $editedVacancy;
                        $processedCount++;
                    }

                } catch (ConnectionException $e) {
                    $this->warn("Таймаут на странице $i: " . $e->getMessage());
                } catch (\Exception $e) {
                    $this->warn("Ошибка на странице $i: " . $e->getMessage());
                }

                $progressBar->advance();

            }

            $progressBar->finish();
            $this->newLine(2);

            if (empty($editedVacancies)) {
                $this->error('Не удалось собрать ни одной вакансии');
                return Command::FAILURE;
            }

            // Генерация XML
            $filename = 'hirehi_' . $category . '_' . now()->format('Y-m-d_H-i') . '.xml';
            $xmlPath = 'storage/app/public/hirehi/' . $filename;
            
            $xml->createXmlFeed(
                $editedVacancies, 
                'https://hirehi.ru/', 
                $xmlPath
            );

            $this->info("✅ Готово! Обработано вакансий: {$processedCount}");
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
    private function getVacancyDescription($vacancyId): string
    {
        $response = $this->requestHireHi("https://hirehi.ru/api/jobs/{$vacancyId}", [], 30);

        if ($response === null || $response->failed()) {
            return '';
        }

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

        for ($attempt = 0; $attempt <= $this->maxRetries; $attempt++) {
            $this->waitForRequestSlot();

            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; vacancy-feed/1.0)',
                        'Referer' => 'https://hirehi.ru/',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->get($url, $query);
            } catch (ConnectionException $exception) {
                if ($attempt === $this->maxRetries) {
                    $this->warn('HireHi network error: ' . $exception->getMessage());

                    return null;
                }

                $this->waitBeforeRetry(null, $attempt + 1, 'network error');
                continue;
            }

            $lastResponse = $response;

            if ($response->successful() || !in_array($response->status(), [429, 500, 502, 503, 504], true)) {
                return $response;
            }

            if ($attempt === $this->maxRetries) {
                return $response;
            }

            $this->waitBeforeRetry($response, $attempt + 1, 'HTTP ' . $response->status());
        }

        return $lastResponse;
    }

    private function waitForRequestSlot(): void
    {
        if ($this->lastRequestAt !== null) {
            $waitMicroseconds = (int) (($this->requestDelayMs / 1000 - (microtime(true) - $this->lastRequestAt)) * 1_000_000);

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
