<?php

namespace App\Console\Commands\Yandex;

use App\Services\Yandex\YandexCaptchaSolver;
use DateTime;
use Illuminate\Console\Command;
use SimpleXMLElement;

class ConvertVacanciesToXml extends Command
{
    protected $signature = 'vacancies:convert-to-xml
                            {input=storage/app/vacancies.json : Path to input JSON file}
                            {output=storage/app/vacancies.xml : Path to output XML file}';

    protected $description = 'Convert vacancies from JSON to XML format';

    public function handle(YandexCaptchaSolver $solver)
    {
        $inputPath = $this->argument('input');
        $outputPath = $this->argument('output');

        if (!file_exists($inputPath)) {
            $this->error("❌ Файл не найден: {$inputPath}");

            return 1;
        }

        try {
            // Читаем JSON
            $jsonContent = file_get_contents($inputPath);
            $vacanciesData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('❌ Неверный JSON: ' . json_last_error_msg());

                return 1;
            }

            // ✅ Считаем общее количество
            $totalVacancies = 0;
            foreach ($vacanciesData as $direction) {
                $totalVacancies += count(array_filter($direction['vacancies'], fn($v) => $v['available'] ?? false));
            }

            $this->info("🚀 Найдено вакансий: {$totalVacancies}");

            // ✅ ПРОГРЕСС-БАР
            $bar = $this->output->createProgressBar($totalVacancies);
            $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%');
            $bar->start();

            date_default_timezone_set('Europe/Moscow');
            $date = new DateTime;

            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><source></source>');
            $xml->addAttribute('creation-time', $date->format('Y-m-d H:i:s') . ' GMT+3');
            $xml->addAttribute('host', 'crowd.yandex.ru');

            $vacanciesNode = $xml->addChild('vacancies');
            $processedCount = 0;

            foreach ($vacanciesData as $directionName => $direction) {
                foreach ($direction['vacancies'] as $vacancy) {
                    if (($vacancy['available'] ?? false)) {
                        $this->addVacancyToXml($vacanciesNode, $vacancy, $solver);
                        $processedCount++;
                        $bar->advance();

                        // Статистика каждые 10
                        if ($processedCount % 10 === 0 || $processedCount === $totalVacancies) {
                            $this->info("\n📊 {$processedCount}/{$totalVacancies} | RAM: " . $this->formatBytes(memory_get_usage(true)));
                        }
                    }
                }
            }

            $bar->finish();
            $this->newLine(2);

            // Сохраняем
            $xmlString = $this->formatXml($xml);
            file_put_contents($outputPath, $xmlString);

            $this->info("✅ ГОТОВО! {$outputPath}");
            $this->info("📈 Обработано: {$processedCount} вакансий");
            $this->info('💾 XML: ' . $this->formatBytes(strlen($xmlString)));
            $this->info('🧠 Пик RAM: ' . $this->formatBytes(memory_get_peak_usage(true)));

            return 0;

        } catch (\Exception $e) {
            $this->error('💥 ОШИБКА: ' . $e->getMessage());

            return 1;
        }
    }

    private function addVacancyToXml(SimpleXMLElement $parent, array $vacancy, $solver)
    {
        $vacancyNode = $parent->addChild('vacancy');

        $vacancyNode->addChild('url', $this->escapeForXml($vacancy['url'] ?? ''));
        $vacancyNode->addChild('mobile-url', $this->escapeForXml($vacancy['url'] ?? ''));

        date_default_timezone_set('Europe/Moscow');
        $date = new DateTime;
        $vacancyNode->addChild('creation-date', $date->format('Y-m-d H:i:s') . ' GMT+3');

        $this->processSalary($vacancyNode, $vacancy['payment'] ?? '');
        $this->processCategories($vacancyNode, $vacancy);
        $vacancyNode->addChild('job-name', $this->escapeForXml($this->cleanupText($vacancy['title'])));

        $this->processEmploymentAndSchedule($vacancyNode, $vacancy);

        // ✅ ОПИСАНИЕ С ПРОГРЕССОМ
        $description = $this->getDescriptionFromUrl($vacancy['url'], $solver);
        $description = $this->cleanupSpecialCharacters($description);
        $vacancyNode->addChild('description', $this->escapeForXml($description));

        $this->processTerms($vacancyNode, $vacancy);
        $this->processRequirements($vacancyNode, $vacancy);
        $this->processAddresses($vacancyNode, $vacancy);
        $this->processCompany($vacancyNode);
    }

    private function processSalary(SimpleXMLElement $vacancyNode, string $payment): void
    {
        $salary = $this->normalizeSalary($payment);
        if ($salary) {
            $vacancyNode->addChild('salary', $salary);
            $vacancyNode->addChild('currency', 'RUB');
        }
    }

    private function normalizeSalary(?string $payment): string
    {
        if (empty($payment)) {
            return '';
        }

        $cleaned = str_replace(['&nbsp;', ' на руки', ' ₽'], ' ', $payment);
        $cleaned = str_replace("\xE2\x80\xAF", ' ', $cleaned);
        $cleaned = explode(' +', $cleaned)[0];
        $cleaned = preg_replace('/[^\d\sотдо]/u', '', $cleaned);
        $normalized = trim(preg_replace('/\s+/', ' ', $cleaned));

        return preg_replace('/(\d)\s+(\d)/', '$1$2', $normalized);
    }

    private function processCategories(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $directionName = $vacancy['tags']['direction'][0] ?? 'Без специальной подготовки';
        $categoryNode = $vacancyNode->addChild('category');
        $categoryNode->addChild('industry', $this->escapeForXml($directionName));
    }

    private function processEmploymentAndSchedule(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $tags = $vacancy['tags'] ?? [];
        $employment = !empty($tags['employment']) && in_array('частичная', $tags['employment']) ? 'частичная' : 'полная';
        $vacancyNode->addChild('employment', $employment);

        $schedule = ($tags['remotely'] ?? '') === 'локально' ? 'полный день' : 'удаленная работа';
        $vacancyNode->addChild('schedule', $schedule);
    }

    private function processTerms(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $termNode = $vacancyNode->addChild('term');
        $tags = $vacancy['tags'] ?? [];
        if (($tags['remotely'] ?? '') === 'удалённо') {
            $termNode->addChild('text', $this->escapeForXml('удаленная работа'));
        }
    }

    private function processRequirements(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $requirementNode = $vacancyNode->addChild('requirement');
        $experience = $vacancy['tags']['experience'] ?? 'без опыта';
        $requirementNode->addChild('experience', $this->escapeForXml($experience));
    }

    private function processAddresses(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $addressesNode = $vacancyNode->addChild('addresses');
        $addressNode = $addressesNode->addChild('address');
        $tags = $vacancy['tags'] ?? [];
        $addressNode->addChild('location', ($tags['remotely'] ?? '') ?: 'Удаленная работа');
    }

    private function processCompany(SimpleXMLElement $vacancyNode)
    {
        $companyNode = $vacancyNode->addChild('company');
        $companyNode->addChild('name', 'Яндекс');
        $companyNode->addChild('hr-agency', 'false');
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
            '&#xA0;'
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
            '&#xA0;'
        ];

        $text = str_replace($nbspVariants, ' ', $text);

        return trim($text);
    }

    private function escapeForXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function formatXml(SimpleXMLElement $xml)
    {
        $dom = new \DOMDocument('1.0');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = true;
        $dom->loadXML($xml->asXML());

        return $dom->saveXML();
    }

    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision) . ' ' . $units[$i];
    }

    private function getDescriptionFromUrl($url, $solver)
    {
        try {
            $html = $solver->get($url);
            if ($html === null || str_contains($html, 'не робот')) {
                return '';
            }

            $sections = [];
            $titleIds = ['it__title-1', 'it__title-2', 'it__title-3'];
            $descIds = ['it__description-1', 'it__description-2', 'it__description-3'];

            for ($i = 0; $i < count($titleIds); $i++) {
                $titleHtml = $this->getSectionById($html, $titleIds[$i]);
                $descHtml = $this->getSectionById($html, $descIds[$i]);

                if ($titleHtml && $descHtml) {
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
                $title = trim(strip_tags($this->getH2Text($benefitsTitle)));
                $items = $this->extractListItems($benefitsDesc);
                if ($title && !empty($items)) {
                    $sections[] = $title . ':'. '<br>' . implode(';<br>', $items);
                }
            }

            return implode('<br>', $sections);
        } catch (\Exception $e) {
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
