<?php

namespace App\Console\Commands\Yandex;

use DateTime;
use Illuminate\Console\Command;
use SimpleXMLElement;

class ConvertVacanciesToXml extends Command
{
    protected $signature = 'vacancies:convert-to-xml
                            {input=storage/app/vacancies.json : Path to input JSON file}
                            {output=storage/app/vacancies.xml : Path to output XML file}';

    protected $description = 'Convert vacancies from JSON to XML format';

    public function handle()
    {
        $inputPath = $this->argument('input');
        $outputPath = $this->argument('output');

        // Проверяем существование входного файла
        if (!file_exists($inputPath)) {
            $this->error("Input file not found: {$inputPath}");

            return 1;
        }

        try {
            // Читаем JSON файл
            $jsonContent = file_get_contents($inputPath);
            $vacanciesData = json_decode($jsonContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error('Invalid JSON format: ' . json_last_error_msg());

                return 1;
            }

            // Создаем XML структуру
            date_default_timezone_set('Europe/Moscow');

            $date = new DateTime;
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><source></source>');
            $xml->addAttribute('creation-time', $date->format('Y-m-d H:i:s') . ' GMT+3');
            $xml->addAttribute('host', 'crowd.yandex.ru');

            $vacanciesNode = $xml->addChild('vacancies');

            // Обрабатываем каждое направление и вакансии
            foreach ($vacanciesData as $direction) {
                foreach ($direction['vacancies'] as $vacancy) {
                    $this->addVacancyToXml($vacanciesNode, $vacancy);
                }
            }

            // Сохраняем XML файл
            $xmlString = $this->formatXml($xml);
            file_put_contents($outputPath, $xmlString);

            $this->info("Successfully converted vacancies to XML: {$outputPath}");
            $this->info('Total vacancies processed: ' . count($vacanciesNode->vacancy));

            return 0;

        } catch (\Exception $e) {
            $this->error('Error during conversion: ' . $e->getMessage());

            return 1;
        }
    }

    private function addVacancyToXml(SimpleXMLElement $parent, array $vacancy)
    {
        $vacancyNode = $parent->addChild('vacancy');

        // URL
        $vacancyNode->addChild('url', $this->escapeForXml($vacancy['url'] ?? ''));
        $vacancyNode->addChild('mobile-url', $this->escapeForXml($vacancy['url'] ?? ''));

        date_default_timezone_set('Europe/Moscow');

        $date = new DateTime;
        $vacancyNode->addChild('creation-date', $date->format('Y-m-d H:i:s') . ' GMT+3');

        // Зарплата
        $this->processSalary($vacancyNode, $vacancy['payment'] ?? '');

        // Категории
        $this->processCategories($vacancyNode, $vacancy);

        // Название должности
        $vacancyNode->addChild('job-name', $this->escapeForXml($this->cleanupText($vacancy['title'])));

        // Занятость и график
        $this->processEmploymentAndSchedule($vacancyNode, $vacancy);

        // Описание - исправляем &nbsp; на обычные пробелы
        $description = $this->cleanupText($vacancy['description'] ?? '');
        $vacancyNode->addChild('description', $this->escapeForXml($description));

        // Условия
        $this->processTerms($vacancyNode, $vacancy);

        // Требования
        $this->processRequirements($vacancyNode, $vacancy);

        // Адреса (для удаленной работы)
        $this->processAddresses($vacancyNode, $vacancy);

        // Компания
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

        // Заменяем все специальные символы и пробелы
        $cleaned = str_replace(['&nbsp;', ' на руки', ' ₽'], ' ', $payment);
        $cleaned = str_replace("\xE2\x80\xAF", ' ', $cleaned); // Узкий пробел

        // Убираем всё после " +"
        $cleaned = explode(' +', $cleaned)[0];

        // Оставляем только цифры, пробелы и ключевые слова
        $cleaned = preg_replace('/[^\d\sотдо]/u', '', $cleaned);

        // Нормализуем пробелы
        $normalized = trim(preg_replace('/\s+/', ' ', $cleaned));

        // Убираем пробелы между цифрами
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

        // Тип занятости
        $employment = 'полная';
        if (!empty($tags['employment'])) {
            $employment = in_array('частичная', $tags['employment']) ? 'частичная' : 'полная';
        }
        $vacancyNode->addChild('employment', $employment);

        // График работы
        $schedule = 'удаленная работа';
        if (($tags['remotely'] ?? '') === 'локально') {
            $schedule = 'полный день';
        }
        $vacancyNode->addChild('schedule', $schedule);
    }

    private function processTerms(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $termNode = $vacancyNode->addChild('term');

        $text = [];
        $tags = $vacancy['tags'] ?? [];

        if (($tags['remotely'] ?? '') === 'удалённо') {
            $text[] = 'удаленная работа';
        }

        if (!empty($text)) {
            $termNode->addChild('text', $this->escapeForXml(implode(', ', $text)));
        }
    }

    private function processRequirements(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $requirementNode = $vacancyNode->addChild('requirement');

        // Опыт работы
        $experience = $vacancy['tags']['experience'] ?? 'без опыта';
        $requirementNode->addChild('experience', $this->escapeForXml($experience));

    }

    private function processAddresses(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $addressesNode = $vacancyNode->addChild('addresses');
        $addressNode = $addressesNode->addChild('address');

        $tags = $vacancy['tags'] ?? [];

        if ($tags['remotely'] ?? '') {
            $addressNode->addChild('location', $tags['remotely']);
        } else {
            $addressNode->addChild('location', 'Удаленная работа');
        }
    }

    private function processCompany(SimpleXMLElement $vacancyNode)
    {
        $companyNode = $vacancyNode->addChild('company');

        $companyNode->addChild('name', 'Яндекс');
        $companyNode->addChild('hr-agency', 'false');
    }

    private function cleanupText(string $text): string
    {
        // Заменяем &nbsp; на обычные пробелы
        $text = str_replace('&nbsp;', ' ', $text);

        // Декодируем другие HTML entities
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Удаляем HTML теги
        $text = strip_tags($text);

        // Очищаем от лишних пробелов
        $text = preg_replace('/\s+/', ' ', $text);

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
}
