<?php

namespace App\Console\Commands\Yandex;

use App\Services\Yandex\YandexCaptchaSolver;
use App\Services\YandexFeedXmlFormat;
use DateTime;
use Illuminate\Console\Command;

class PrepareDataToFormat extends Command
{
    protected $signature = 'yandex:prepare-data-to-format
                           {input=storage/app/vacancies.json : Path to input JSON file}
                           {output=storage/app/public/yandex/vacancies.xml : Path to output XML file}';

    protected $description = 'Convert vacancies from JSON to XML format';

    public function handle(YandexCaptchaSolver $solver, YandexFeedXmlFormat $xml)
    {
        $this->info('🚀 Начало выполнения команды подготовки данных для Yandex');
        $this->line('');

        // Аргументы
        $inputPath = $this->argument('input');
        $outputPath = $this->argument('output');

        $this->info("📂 Входной файл: {$inputPath}");
        $this->info("📄 Выходной файл: {$outputPath}");
        $this->line('');

        // Проверка существования файла
        $this->comment('🔍 Проверка существования входного файла...');
        if (!file_exists($inputPath)) {
            $this->error("❌ Файл не найден: {$inputPath}");

            return 1;
        }
        $this->info('✅ Файл найден');
        $this->line('');

        // Чтение JSON
        $this->comment('📖 Чтение JSON файла...');
        $jsonContent = file_get_contents($inputPath);
        $fileSize = round(strlen($jsonContent) / 1024, 2); // KB
        $this->info("✅ Файл прочитан ({$fileSize} KB)");

        $vacanciesData = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->error('❌ Неверный JSON: ' . json_last_error_msg());

            return 1;
        }
        $this->info('✅ JSON валиден');

        $totalDirections = count($vacanciesData);
        $this->info("📊 Найдено направлений: {$totalDirections}");
        $this->line('');

        // Собираем все вакансии из всех направлений в один массив
        $this->comment('🔍 Сбор вакансий из всех направлений...');
        $allVacancies = [];
        $directionCounter = 0;
        $totalVacancies = 0;

        foreach ($vacanciesData as $directionName => $direction) {
            $directionCounter++;
            $vacancyCount = count($direction['vacancies'] ?? []);
            $totalVacancies += $vacancyCount;

            $this->info("📌 Направление {$directionCounter}: {$directionName} - {$vacancyCount} вакансий");

            // Добавляем вакансии текущего направления в общий массив
            if (!empty($direction['vacancies'])) {
                $allVacancies = array_merge($allVacancies, $direction['vacancies']);
            }
        }

        $this->info("📊 Всего вакансий собрано: {$totalVacancies}");
        $this->line('');

        // Подготовка данных
        $this->comment('🛠️  Подготовка данных вакансий...');
        $startTime = microtime(true);

        $preparedVacancies = $this->prepareData($allVacancies, $solver);

        $endTime = microtime(true);
        $processingTime = round($endTime - $startTime, 2);
        $this->info("✅ Данные подготовлены ({$processingTime} сек.)");

        // Создание XML
        $this->comment('📝 Создание XML фида...');
        $xmlCreationStart = microtime(true);

        $xml->createXmlFeed($preparedVacancies, 'crowd.yandex.ru', 'storage/app/public/yandex/YandexVacancies' . today()->toDateString() . '.xml');

        $xmlCreationEnd = microtime(true);
        $xmlCreationTime = round($xmlCreationEnd - $xmlCreationStart, 2);
        $this->info("✅ XML фид создан ({$xmlCreationTime} сек.)");

        $this->info('🎉 Все направления успешно обработаны!');
        $this->info("📄 XML файл сохранен: {$outputPath}");
        $this->line('');

        return 0;
    }

    private function prepareData(array $vacanciesData, $solver): array
    {
        date_default_timezone_set('Europe/Moscow');
        $date = new DateTime;

        $vacancies = [];
        $total = count($vacanciesData);
        $availableCount = 0;
        $processedCount = 0;

        $this->comment("📊 Анализ доступных вакансий...");

        // Считаем доступные вакансии
        foreach ($vacanciesData as $vacancy) {
            if ($vacancy['available']) {
                $availableCount++;
            }
        }

        $this->info("Доступно вакансий для обработки: {$availableCount}/{$total}");
        $this->line('');

        if ($availableCount === 0) {
            $this->warn('⚠️  Нет доступных вакансий для обработки');
            return [];
        }

        $this->comment('🔄 Начинаю обработку вакансий...');

        $progressBar = $this->output->createProgressBar($availableCount);
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s%');
        $progressBar->start();

        foreach ($vacanciesData as $index => $vacancy) {
            if ($vacancy['available']) {
                $processedCount++;
                $currentNumber = $processedCount;

                // Показываем информацию о первых 3 вакансиях
                if ($currentNumber <= 3) {
                    $this->line('');
                    $this->info("🔹 Вакансия #{$currentNumber}: {$vacancy['title']}");
                    $this->line("   📍 URL: {$vacancy['url']}");
                    $this->line("   💰 Оплата: {$vacancy['payment']}");

                    if ($currentNumber === 1) {
                        $this->comment('   ⏳ Получаю описание...');
                    }
                }

                $editedVacancy = [];

                $editedVacancy['url'] = $this->escapeForXml($vacancy['url'] ?? '');
                $editedVacancy['mobile_url'] = $this->escapeForXml($vacancy['url'] ?? '');
                $editedVacancy['creation_date'] = $date->format('Y-m-d H:i:s') . ' GMT+3';
                $editedVacancy['payment'] = $vacancy['payment'] ?? '';
                $editedVacancy['category']['industry'] = $this->escapeForXml($vacancy['tags']['direction'][0] ?? 'Без специальной подготовки');
                $editedVacancy['job_name'] = $this->escapeForXml($this->cleanupText($vacancy['title']));
                $editedVacancy['employment'] = !empty($vacancy['tags']['employment']) && in_array('частичная', $vacancy['tags']['employment']) ? 'частичная' : 'полная';
                $editedVacancy['schedule'] = ($vacancy['tags']['remotely'] ?? '') === 'локально' ? 'полный день' : 'удаленная работа';

                // Получение описания
                $description = $this->getDescriptionFromUrl($vacancy['url'], $solver, $currentNumber);
                $description = $this->cleanupSpecialCharacters($description);

                // Используем описание из вакансии, если не удалось получить с сайта
                $editedVacancy['description'] = !empty($description) ? $description : ($vacancy['description'] ?? '');

                if (($vacancy['tags']['remotely'] ?? '') === 'удалённо') {
                    $editedVacancy['term']['text'] = $this->escapeForXml('удаленная работа');
                }

                $editedVacancy['requirement']['experience'] = $this->escapeForXml($vacancy['tags']['experience'] ?? 'без опыта');
                $editedVacancy['addresses']['address']['location'] = 'Россия, Москва';

                $editedVacancy['company_name'] = 'Яндекс';
                $editedVacancy['hr_agency'] = 'false';

                $vacancies[] = $editedVacancy;

                // Показываем статус для первых 3 вакансий
                if ($currentNumber <= 3) {
                    $descLength = strlen($editedVacancy['description']);
                    $this->info("   ✅ Готово (длина описания: {$descLength} символов)");
                    $this->line('');
                }

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->line('');
        $this->line('');
        $this->info("✅ Обработано вакансий: {$processedCount} из {$availableCount} доступных");

        if ($processedCount > 0) {
            $avgDescLength = 0;
            foreach ($vacancies as $v) {
                $avgDescLength += strlen($v['description'] ?? '');
            }
            $avgDescLength = round($avgDescLength / $processedCount);
            $this->info("📊 Средняя длина описания: {$avgDescLength} символов");
        }

        return $vacancies;
    }

    private function escapeForXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function cleanupText(string $text): string
    {
        // 1. Декодируем HTML-сущности ПЕРЕД всем
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 2. Удаляем все варианты неразрывных пробелов (включая UTF-8)
        $nbspVariants = [
            "\xC2\xA0",  // &nbsp; (NO-BREAK SPACE)
            "\xE2\x80\xAF", // NARROW NO-BREAK SPACE
            "\x00A0",    // &nbsp;
            "\u{00A0}",  // Unicode NO-BREAK SPACE
            "\u{202F}",  // NARROW NO-BREAK SPACE
            '&nbsp;',
            '&#160;',
            '&#xa0;',
            '&#xA0;',
        ];
        $text = str_replace($nbspVariants, ' ', $text);

        // 3. Убираем теги
        $text = strip_tags($text);

        // 4. Нормализуем все пробелы
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function cleanupSpecialCharacters(string $text): string
    {
        $nbspVariants = [
            "\xC2\xA0",  // &nbsp; (NO-BREAK SPACE)
            "\xE2\x80\xAF", // NARROW NO-BREAK SPACE
            "\x00A0",    // &nbsp;
            "\u{00A0}",  // Unicode NO-BREAK SPACE
            "\u{202F}",  // NARROW NO-BREAK SPACE
            '&nbsp;',
            '&#160;',
            '&#xa0;',
            '&#xA0;',
        ];

        $text = str_replace($nbspVariants, ' ', $text);

        return trim($text);
    }

    private function getDescriptionFromUrl($url, $solver, $currentNumber = null)
    {
        try {
            if ($currentNumber && $currentNumber <= 3) {
                $this->line("   🌐 Загрузка страницы: {$url}");
            }

            $html = $solver->get($url);

            if ($html === null) {
                if ($currentNumber && $currentNumber <= 3) {
                    $this->warn('   ⚠️  Получен пустой ответ от сервера');
                }
                return '';
            }

            if (str_contains($html, 'не робот')) {
                if ($currentNumber && $currentNumber <= 3) {
                    $this->warn('   ⚠️  Обнаружена капча, пропускаю...');
                }
                return '';
            }

            $sections = [];
            $titleIds = ['it__title-1', 'it__title-2', 'it__title-3'];
            $descIds = ['it__description-1', 'it__description-2', 'it__description-3'];

            $foundSections = 0;
            for ($i = 0; $i < count($titleIds); $i++) {
                $titleHtml = $this->getSectionById($html, $titleIds[$i]);
                $descHtml = $this->getSectionById($html, $descIds[$i]);

                if ($titleHtml && $descHtml) {
                    $foundSections++;
                    $title = trim(strip_tags($this->getH2Text($titleHtml)));
                    $items = $this->extractListItems($descHtml);
                    if ($title && !empty($items)) {
                        $sections[] = $title . ':' . '<br>' . implode(';<br>', $items);
                    }
                }
            }

            // Benefits блок
            $benefitsTitle = $this->getSectionById($html, 'bitem__title-1');
            $benefitsDesc = $this->getSectionById($html, 'ibitem__description-1');
            if ($benefitsTitle && $benefitsDesc) {
                $foundSections++;
                $title = trim(strip_tags($this->getH2Text($benefitsTitle)));
                $items = $this->extractListItems($benefitsDesc);
                if ($title && !empty($items)) {
                    $sections[] = $title . ':'. '<br>' . implode(';<br>', $items);
                }
            }

            if ($currentNumber && $currentNumber <= 3) {
                if ($foundSections > 0) {
                    $this->info("   📊 Найдено секций: {$foundSections}");
                } else {
                    $this->warn('   ⚠️  Не найдено ни одной секции с описанием');
                }
            }

            return implode('<br>', $sections);
        } catch (\Exception $e) {
            if ($currentNumber && $currentNumber <= 3) {
                $this->error('   ❌ Ошибка при получении описания: ' . $e->getMessage());
            }
            return '';
        }
    }

    private function getSectionById($html, $id)
    {
        if (preg_match('/<section[^>]*id="' . preg_quote($id, '/') . '"[^>]*>(.*?)<\/section>/si', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function getH2Text($html)
    {
        if (preg_match('/<h2[^>]*>(.*?)<\/h2>/si', $html, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private function extractListItems($html)
    {
        $items = [];
        if (preg_match_all('/lc-styled-text__text[^>]*>.*?<ul[^>]*>(.*?)<\/ul[^>]*>/si', $html, $ulMatches)) {
            foreach ($ulMatches[1] as $ulContent) {
                if (preg_match_all('/<li[^>]*>(.*?)<\/li>/si', $ulContent, $liMatches)) {
                    foreach ($liMatches[1] as $li) {
                        $text = trim(html_entity_decode(strip_tags($li)));
                        if ($text && strlen($text) > 5) {
                            $items[] = $this->cleanupText($text);
                        }
                    }
                }
            }
        }

        return $items;
    }
}
