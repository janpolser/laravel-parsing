<?php

namespace App\Console\Commands\HireHi;

use App\Services\YandexFeedXmlFormat;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ParseVacancies extends Command
{
    protected $signature = 'hirehi:parse-vacancies 
                            {--category=development : Категория вакансий}
                            {--search= : Поисковый запрос}
                            {--max-pages=0 : Максимум страниц (0 - все)}';

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

    public function handle(YandexFeedXmlFormat $xml)
    {
        $perPage = 100;
        $category = $this->option('category');
        $search = $this->option('search');
        $maxPages = (int) $this->option('max-pages');
        $timeout = 60;

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

            $response = Http::timeout($timeout)->get($baseUrl, $queryParams);

            if ($response->failed()) {
                $this->error('Ошибка при получении данных: ' . $response->status());
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
                    
                    $response = Http::timeout($timeout)
                        ->retry(3, 1000)
                        ->get($baseUrl, $queryParams);

                    if ($response->failed()) {
                        $this->warn("Ошибка при запросе страницы $i");
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
                            sleep(1); // Пауза между запросами деталей
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

                if ($i < $totalPages) {
                    sleep(1);
                }
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
        try {
            $response = Http::timeout(30)
                ->get("https://hirehi.ru/api/jobs/{$vacancyId}");

            if ($response->failed()) {
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

        } catch (\Exception $e) {
            return '';
        }
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