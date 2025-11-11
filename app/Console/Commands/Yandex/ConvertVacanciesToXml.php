<?php

namespace App\Console\Commands\Yandex;

use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
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

            $date = new DateTime();
            $xml = new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><source></source>');
            $xml->addAttribute('creation-time', $date->format('Y-m-d H:i:s') . ' GMT+3');
            $xml->addAttribute('host', 'crowd.yandex.ru');

            $vacanciesNode = $xml->addChild('vacancies');

            // Обрабатываем каждое направление и вакансии
            foreach ($vacanciesData as $direction) {
                foreach ($direction['vacancies'] as $vacancy) {
                    $this->addVacancyToXml($vacanciesNode, $vacancy, $direction);
                }
            }

            // Сохраняем XML файл
            $xmlString = $this->formatXml($xml);
            file_put_contents($outputPath, $xmlString);

            $this->info("Successfully converted vacancies to XML: {$outputPath}");
            $this->info("Total vacancies processed: " . count($vacanciesNode->vacancy));

            return 0;

        } catch (\Exception $e) {
            $this->error("Error during conversion: " . $e->getMessage());
            return 1;
        }
    }

    private function addVacancyToXml(SimpleXMLElement $parent, array $vacancy, array $direction)
    {
        $vacancyNode = $parent->addChild('vacancy');

        // URL
        $vacancyNode->addChild('url', $this->escapeForXml($vacancy['url'] ?? ''));
        $vacancyNode->addChild('mobile-url', $this->escapeForXml($vacancy['url'] ?? ''));

        date_default_timezone_set('Europe/Moscow');

        $date = new DateTime();
        $vacancyNode->addChild('creation-date', $date->format('Y-m-d H:i:s') . ' GMT+3');
//        $vacancyNode->addChild('update-date', $date->format('Y-m-d H:i:s') . ' GMT+3');

        // Зарплата
        $this->processSalary($vacancyNode, $vacancy['payment'] ?? '');

        // Категории
        $this->processCategories($vacancyNode, $vacancy, $direction);

        // Название должности
        $vacancyNode->addChild('job-name', $this->escapeForXml($vacancy['title']));

        // Занятость и график
        $this->processEmploymentAndSchedule($vacancyNode, $vacancy);

        // Описание - исправляем &nbsp; на обычные пробелы
        $description = $this->cleanupText($vacancy['description'] ?? '');
        $vacancyNode->addChild('description', $this->escapeForXml($description));

        // Обязанности (используем описание как обязанности)
        $vacancyNode->addChild('duty', $this->escapeForXml($description));

        // Условия
        $this->processTerms($vacancyNode, $vacancy);

        // Требования
        $this->processRequirements($vacancyNode, $vacancy);

        // Адреса (для удаленной работы)
        $this->processAddresses($vacancyNode, $vacancy);

        // Компания
        $this->processCompany($vacancyNode);

        // Campaign ID
        $vacancyNode->addChild('campaign', $this->generateUuid());
    }

    private function processSalary(SimpleXMLElement $vacancyNode, string $payment)
    {
        // Парсим зарплату из строки
        $salary = '';
        $currency = 'руб';

        if (!empty($payment)) {
            // Извлекаем числа из строки зарплаты
            preg_match_all('/\d+[\s\d]*\d+/', $payment, $matches);

            $clean_string = str_replace('&nbsp;', ' ', $payment);
            $clean_string = str_replace(["\u{202F}", ' на руки', ' ₽'], '', $clean_string);
            $clean_string = explode(' +', $clean_string)[0];

// Оставляем только цифры, пробелы и слова "от"/"до"
            $clean_string = preg_replace('/[^\d\sотдо]/u', '', $clean_string);
            $clean_string = preg_replace('/\s+/', ' ', $clean_string); // убираем лишние пробелы
            $clean_string = trim($clean_string);
            dump("было - " . $payment . " стало - " . $clean_string);

            if (!empty($matches[0])) {
                $numbers = $matches[0];
                if (count($numbers) >= 2) {
                    $salary = implode('-', array_slice($numbers, 0, 2));
                } else {
                    $salary = $numbers[0];
                }
            }
        }

        $vacancyNode->addChild('salary', $salary);
        $vacancyNode->addChild('currency', $currency);
    }

    private function processCategories(SimpleXMLElement $vacancyNode, array $vacancy, array $direction)
    {
        $directionName = $direction['direction-name'] ?? 'Другое';

        $categoryNode = $vacancyNode->addChild('category');
        $categoryNode->addChild('industry', $this->escapeForXml($directionName));

        // Используем теги как специализации
        $tags = $vacancy['tags'] ?? [];
        $specializations = array_merge(
            $tags['direction'] ?? [],
            $tags['employment'] ?? []
        );

        if (!empty($specializations)) {
            $categoryNode->addChild('specialization', $this->escapeForXml(implode(', ', $specializations)));
        } else {
            $categoryNode->addChild('specialization', $this->escapeForXml($directionName));
        }
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
        $termNode->addChild('contract', 'постоянный');

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

        // Возраст
        $requirementNode->addChild('age', '18-60');

        // Пол (не указан в исходных данных)
        $requirementNode->addChild('sex', '');

        // Образование
        $requirementNode->addChild('education', 'Высшее образование предпочтительно');

        // Опыт работы
        $experience = $vacancy['tags']['experience'] ?? 'без опыта';
        $requirementNode->addChild('experience', $this->escapeForXml($experience));

        // Квалификация
        $qualification = [];
        $qualification[] = "Опыт: " . ($vacancy['tags']['experience'] ?? 'без опыта');

        if (!empty($qualification)) {
            $requirementNode->addChild('qualification', $this->escapeForXml(implode('. ', $qualification)));
        }
    }

    private function processAddresses(SimpleXMLElement $vacancyNode, array $vacancy)
    {
        $addressesNode = $vacancyNode->addChild('addresses');
        $addressNode = $addressesNode->addChild('address');

        $tags = $vacancy['tags'] ?? [];

        if (($tags['remotely'] ?? '') === 'локально') {
            $addressNode->addChild('location', 'Москва, офис Яндекса');
            $addressNode->addChild('metro', 'Охотный ряд');
        } else {
            $addressNode->addChild('location', 'Удаленная работа');
        }
    }

    private function processCompany(SimpleXMLElement $vacancyNode)
    {
        $companyNode = $vacancyNode->addChild('company');

        $companyNode->addChild('name', 'Яндекс');
        $companyNode->addChild('description', $this->escapeForXml('Яндекс — российская транснациональная компания в отрасли информационных технологий, чьё головное юридическое лицо зарегистрировано в Нидерландах, владеющая одноимённой системой поиска в Сети, интернет-порталом и веб-службами в нескольких странах. Наиболее заметное положение занимает на рынках России, Беларуси, Казахстана и Турции.'));
        $companyNode->addChild('logo', 'https://yastatic.net/s3/lyceum/landing/images/logo-ya.svg');
        $companyNode->addChild('site', 'https://yandex.ru/');
        $companyNode->addChild('email', 'recruitment@yandex-team.ru');
        $companyNode->addChild('phone', '+7-495-739-70-00');
        $companyNode->addChild('hr-agency', 'false');
    }

    private function generateUuid()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    private function formatDateForXml()
    {
        return '2014-06-22 22:00:22 GMT+2';
    }

    /**
     * Очищает текст от HTML entities и заменяет &nbsp; на обычные пробелы
     */
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

    /**
     * Экранирует специальные XML символы
     */
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
