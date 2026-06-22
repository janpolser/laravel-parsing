<?php

namespace App\Console\Commands\Magnit;

use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CollectVacancy extends Command
{
    protected $signature = 'magnit:collect-vacancy-magnit';

    protected $description = 'Парсинг вакансий Магнит с устойчивостью и потоковой записью';

    private const DETAIL_URL = 'https://rabota.magnit.ru/api/v1/vacancy/';
    private const DETAIL_CACHE_LIMIT = 200;

    private const PROPERTY_VALUE_NAMES = [
        'fullDay' => 'полный день',
        'shift' => 'сменный график',
        'flexible' => 'гибкий график',
        'remote' => 'удаленно',
        'office' => 'офис',
        'hybrid' => 'гибрид',
        'secondary' => 'среднее',
        'higher' => 'высшее',
    ];

    private array $detailCache = [];

    public function handle()
    {
        $this->info('Начало сбора вакансий Магнит...');

        $filePath = 'storage/app/public/magnit/MagnitVacancies' . today() . '.xml';
        $tmpPath = $filePath . '.tmp';
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        if (is_file($tmpPath)) {
            unlink($tmpPath);
        }

        // Запрос локаций
        $this->info('Получение списка локаций...');
        $locationResponse = $this->safeRequest('https://rabota.magnit.ru/api/v1/locality?page=1&per_page=20000');
        $locations = array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'slug' => $i['slug']], $locationResponse['results'] ?? []);

        if (empty($locations)) {
            $this->error('Не удалось получить список локаций');
            return 1;
        }

        $this->info('Найдено локаций: ' . count($locations));

        $writer = $this->initXmlWriter($tmpPath, 'https://rabota.magnit.ru');

        try {
            date_default_timezone_set('Europe/Moscow');
            $date = new DateTime;

            $totalLocations = count($locations);
            $processedLocations = 0;
            $totalVacancies = 0;

            // Прогресс-бар для обработки локаций
            $locationsProgressBar = $this->output->createProgressBar($totalLocations);
            $locationsProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
            $locationsProgressBar->setMessage('Обработка локаций...');
            $locationsProgressBar->start();

            foreach ($locations as $item) {
                $page = 1;
                $vacanciesInLocation = 0;

                // Прогресс-бар для страниц внутри локации
                $pagesProgressBar = null;

                do {
                    $url = 'https://rabota.magnit.ru/api/v1/vacancy?locality_id[]=' . $item['id'] .
                        '&overview=list&per_page=500&page=' . $page;

                    $vacancyResponse = $this->safeRequest($url);

                    if (!$vacancyResponse || !isset($vacancyResponse['results']) || !is_array($vacancyResponse['results'])) {
                        break;
                    }

                    // Создаем прогресс-бар для страниц при первой итерации
                    if ($page === 1) {
                        $totalPages = $vacancyResponse['pagination']['total_pages'] ?? 1;
                        $pagesProgressBar = $this->output->createProgressBar($totalPages);
                        $pagesProgressBar->setFormat('  Страница %current%/%max% [%bar%] %percent:3s%%');
                        $pagesProgressBar->start();
                    }

                    foreach ($vacancyResponse['results'] as $vacancy) {
                        if (!empty($vacancy['active'])) {
                            $detail = $this->fetchVacancyDetail((string)($vacancy['id'] ?? ''));
                            if (!empty($detail)) {
                                $vacancy = array_replace($vacancy, $detail);
                            }

                            $this->writeVacancyXml($writer, $this->mapVacancyToFeed($vacancy, $item, $date));
                            $vacanciesInLocation++;
                            $totalVacancies++;
                        }
                    }

                    // Обновляем прогресс-бар страниц
                    if ($pagesProgressBar) {
                        $pagesProgressBar->advance();
                    }

                    $hasNextPage = false;
                    if (isset($vacancyResponse['next']) && !empty($vacancyResponse['next'])) {
                        $hasNextPage = true;
                    } elseif (isset($vacancyResponse['pagination']) &&
                        $page < $vacancyResponse['pagination']['total_pages']) {
                        $hasNextPage = true;
                    } elseif (count($vacancyResponse['results']) == 500) {
                        $hasNextPage = true;
                    }

                    unset($vacancyResponse);
                    gc_collect_cycles();

                    $page++;

                } while ($hasNextPage);

                // Завершаем прогресс-бар страниц для текущей локации
                if ($pagesProgressBar) {
                    $pagesProgressBar->finish();
                    $pagesProgressBar->clear();
                    $this->line("  Найдено вакансий в локации '{$item['name']}': {$vacanciesInLocation}");
                }

                // Обновляем основной прогресс-бар
                $processedLocations++;
                $locationsProgressBar->setMessage("Обработано вакансий: " . $totalVacancies);
                $locationsProgressBar->advance();
            }

            $locationsProgressBar->finish();
            $locationsProgressBar->clear();

            $this->info(PHP_EOL . 'Обработка локаций завершена.');
            $this->info('Всего собрано вакансий: ' . $totalVacancies);

            $this->finishXmlWriter($writer);
            $this->publishTmpFile($tmpPath, $filePath);
            $this->info('Готово! XML файл сохранен: ' . $filePath);

            return 0;
        } catch (\Throwable $e) {
            $writer->flush();
            if (is_file($tmpPath)) {
                unlink($tmpPath);
            }

            throw $e;
        }
    }

    private function initXmlWriter(string $filePath, string $hostName): \XMLWriter
    {
        $writer = new \XMLWriter();
        $writer->openURI($filePath);
        $writer->startDocument('1.0', 'utf-8');
        $writer->startElement('source');
        $writer->writeAttribute('creation-time', (new DateTime)->format('Y-m-d H:i:s') . ' GMT+3');
        $writer->writeAttribute('host', $hostName);
        $writer->startElement('vacancies');

        return $writer;
    }

    private function finishXmlWriter(\XMLWriter $writer): void
    {
        $writer->endElement(); // vacancies
        $writer->endElement(); // source
        $writer->endDocument();
        $writer->flush();
    }

    private function publishTmpFile(string $tmpPath, string $filePath): void
    {
        if (!rename($tmpPath, $filePath)) {
            if (is_file($filePath)) {
                unlink($filePath);
            }

            if (!rename($tmpPath, $filePath)) {
                throw new \RuntimeException("Не удалось опубликовать XML файл: {$filePath}");
            }
        }
    }

    private function writeVacancyXml(\XMLWriter $writer, array $v): void
    {
        $writer->startElement('vacancy');

        $this->writeTextElement($writer, 'url', $v['url'] ?? null);
        $this->writeTextElement($writer, 'mobile-url', $v['mobile_url'] ?? null);
        $this->writeTextElement($writer, 'creation-date', $v['creation_date'] ?? null);
        $this->writeTextElement($writer, 'update-date', $v['update_date'] ?? null);

        $this->writeTextElement($writer, 'salary', $v['salary'] ?? null);
        $this->writeTextElement($writer, 'currency', $v['currency'] ?? null);

        if (!empty($v['category'])) {
            $writer->startElement('category');
            $this->writeTextElement($writer, 'industry', $v['category']['industry'] ?? null);
            $this->writeTextElement($writer, 'specialization', $v['category']['specialization'] ?? null);
            $writer->endElement();
        }

        $this->writeTextElement($writer, 'job-name', $v['job_name'] ?? null);
        $this->writeTextElement($writer, 'employment', $v['employment'] ?? null);
        $this->writeTextElement($writer, 'schedule', $v['schedule'] ?? null);
        $this->writeTextElement($writer, 'description', $v['description'] ?? null);
        $this->writeTextElement($writer, 'duty', $v['duty'] ?? null);

        if (!empty($v['term'])) {
            $writer->startElement('term');
            $this->writeTextElement($writer, 'contract', $v['term']['contract'] ?? null);
            $this->writeTextElement($writer, 'text', $v['term']['text'] ?? null);
            $writer->endElement();
        }

        if (!empty($v['requirement'])) {
            $writer->startElement('requirement');
            $this->writeTextElement($writer, 'age', $v['requirement']['age'] ?? null);
            $this->writeTextElement($writer, 'sex', $v['requirement']['sex'] ?? null);
            $this->writeTextElement($writer, 'education', $v['requirement']['education'] ?? null);
            $this->writeTextElement($writer, 'experience', $v['requirement']['experience'] ?? null);
            $this->writeTextElement($writer, 'qualification', $v['requirement']['qualification'] ?? null);
            $writer->endElement();
        }

        if (!empty($v['addresses'])) {
            $writer->startElement('addresses');
            $addresses = $v['addresses'];
            if (isset($addresses['address'])) {
                $addresses = [$addresses['address']];
            }
            foreach ($addresses as $addrData) {
                $writer->startElement('address');
                $this->writeTextElement($writer, 'location', $addrData['location'] ?? null);
                if (!empty($addrData['metro'])) {
                    foreach ((array) $addrData['metro'] as $m) {
                        $this->writeTextElement($writer, 'metro', $m);
                    }
                }
                $this->writeTextElement($writer, 'lng', $addrData['lng'] ?? null);
                $this->writeTextElement($writer, 'lat', $addrData['lat'] ?? null);
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->startElement('company');
        $this->writeTextElement($writer, 'name', $v['company_name'] ?? null);
        $this->writeTextElement($writer, 'description', $v['company_description'] ?? null);
        $this->writeTextElement($writer, 'logo', $v['company_logo'] ?? null);
        $this->writeTextElement($writer, 'site', $v['company_site'] ?? null);
        foreach (['email', 'phone', 'fax'] as $contactType) {
            if (!empty($v["company_$contactType"])) {
                foreach ((array) $v["company_$contactType"] as $val) {
                    $this->writeTextElement($writer, $contactType, $val);
                }
            }
        }
        if (array_key_exists('hr_agency', $v)) {
            $this->writeTextElement($writer, 'hr-agency', $v['hr_agency'] ? 'true' : 'false');
        }
        $this->writeTextElement($writer, 'contact-name', $v['contact_name'] ?? null);
        $writer->endElement();

        $this->writeTextElement($writer, 'campaign', $v['campaign'] ?? null);

        $writer->endElement();
    }

    private function writeTextElement(\XMLWriter $writer, string $name, $value): void
    {
        if ($value !== null && $value !== '') {
            $writer->startElement($name);
            $writer->text((string) $value);
            $writer->endElement();
        }
    }

    private function fetchVacancyDetail(string $id): array
    {
        if ($id === '') {
            return [];
        }

        if (array_key_exists($id, $this->detailCache)) {
            return $this->detailCache[$id];
        }

        $response = $this->safeRequest(self::DETAIL_URL . rawurlencode($id), 3, 1);
        $detail = $response['results'] ?? [];
        $detail = is_array($detail) ? $detail : [];

        if (count($this->detailCache) >= self::DETAIL_CACHE_LIMIT) {
            array_shift($this->detailCache);
        }

        return $this->detailCache[$id] = $detail;
    }

    private function mapVacancyToFeed(array $vacancy, array $location, DateTime $date): array
    {
        $url = 'https://rabota.magnit.ru/' . ($location['slug'] ?? '') . '/vacancy/' . ($vacancy['id'] ?? '');
        $requirements = $this->composeRequirementText($vacancy);
        $conditions = $this->composeConditionText($vacancy);

        return [
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => $date->format('Y-m-d H:i:s') . ' GMT+3',
            'salary' => $vacancy['salary_human'] ?? $this->formatSalary($vacancy),
            'currency' => 'RUB',
            'category' => array_filter([
                'industry' => $this->categoryIndustry($vacancy),
                'specialization' => $this->categorySpecialization($vacancy),
            ]),
            'job_name' => $vacancy['name'] ?? '',
            'employment' => $this->composeInlineList($this->humanizeItems($this->collectTextItems($vacancy['properties']['schedules'] ?? []))),
            'schedule' => $this->scheduleText($vacancy),
            'description' => $this->composeDescription($vacancy),
            'duty' => $this->composeList($this->collectTextItems($vacancy['responsibilities'] ?? [])),
            'term' => array_filter([
                'text' => $conditions,
            ]),
            'requirement' => array_filter([
                'education' => $this->educationText($vacancy),
                'qualification' => $requirements,
            ]),
            'addresses' => [
                'address' => [
                    'location' => $vacancy['address'] ?? '',
                    'lng' => $vacancy['longitude'] ?? $vacancy['basic_longitude'] ?? null,
                    'lat' => $vacancy['latitude'] ?? $vacancy['basic_latitude'] ?? null,
                ],
            ],
            'company_name' => 'Магнит',
            'company_description' => 'Магнит — одна из крупнейших розничных сетей России.',
            'company_site' => 'https://rabota.magnit.ru',
            'hr_agency' => false,
        ];
    }

    private function composeDescription(array $vacancy): ?string
    {
        $sections = [];

        $duties = $this->composeList($this->collectTextItems($vacancy['responsibilities'] ?? []));
        if ($duties) {
            $sections[] = "Обязанности:\n" . $duties;
        }

        $requirements = $this->composeRequirementText($vacancy);
        if ($requirements) {
            $sections[] = "Требования:\n" . $requirements;
        }

        $conditions = $this->composeConditionText($vacancy);
        if ($conditions) {
            $sections[] = "Условия:\n" . $conditions;
        }

        return $sections ? implode("\n\n", $sections) : null;
    }

    private function composeRequirementText(array $vacancy): ?string
    {
        $items = array_merge(
            $this->collectTextItems($vacancy['requirements'] ?? null),
            $this->collectTextItems($vacancy['professional_skills'] ?? null),
            $this->collectTextItems($vacancy['properties']['key_skills'] ?? null)
        );

        return $this->composeList($items);
    }

    private function composeConditionText(array $vacancy): ?string
    {
        $items = array_merge(
            $this->collectTextItems($vacancy['motivation'] ?? null),
            $this->collectTextItems($vacancy['professional_experience'] ?? null)
        );

        if (!empty($vacancy['salary_tax_info'])) {
            $items[] = 'Зарплата: ' . $vacancy['salary_tax_info'];
        }

        return $this->composeList($items);
    }

    private function categoryIndustry(array $vacancy): ?string
    {
        return $this->firstTextItem($vacancy['properties']['professional_areas'] ?? null)
            ?? $this->firstTextItem($vacancy['properties']['business_directions'] ?? null)
            ?? $this->normalizeText($vacancy['company_division_format']['name'] ?? null);
    }

    private function categorySpecialization(array $vacancy): ?string
    {
        $items = [];
        foreach (($vacancy['properties']['professional_areas'] ?? []) as $area) {
            if (is_array($area)) {
                $items = array_merge($items, $this->collectTextItems($area['specializations'] ?? null));
            }
        }

        if (empty($items)) {
            $items = $this->collectTextItems($vacancy['properties']['block_org_unit'] ?? null);
        }

        return $this->composeInlineList($items);
    }

    private function scheduleText(array $vacancy): ?string
    {
        return $this->firstTextItem($vacancy['schedule'] ?? null)
            ?? $this->normalizeText($vacancy['schedule_title'] ?? null)
            ?? $this->normalizeText($vacancy['work_format'] ?? null);
    }

    private function educationText(array $vacancy): ?string
    {
        return $this->composeInlineList($this->humanizeItems(
            $this->collectTextItems($vacancy['properties']['educations'] ?? null)
        ));
    }

    private function formatSalary(array $vacancy): ?string
    {
        $from = $vacancy['salary_from'] ?? null;
        $to = $vacancy['salary_to'] ?? null;

        if ($from && $to) {
            return 'от ' . number_format((float)$from, 0, '', ' ') . ' до ' . number_format((float)$to, 0, '', ' ') . ' руб';
        }

        if ($from) {
            return 'от ' . number_format((float)$from, 0, '', ' ') . ' руб';
        }

        if ($to) {
            return 'до ' . number_format((float)$to, 0, '', ' ') . ' руб';
        }

        return null;
    }

    private function firstTextItem($value): ?string
    {
        $items = $this->collectTextItems($value);

        return $items[0] ?? null;
    }

    private function collectTextItems($value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_scalar($value)) {
            $text = $this->normalizeText((string)$value);
            return $text === null || $text === '' ? [] : [$text];
        }

        if (!is_array($value)) {
            return [];
        }

        foreach (['name', 'title', 'text', 'value'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $text = $this->normalizeText((string)$value[$key]);
                return $text === null || $text === '' ? [] : [$text];
            }
        }

        $items = [];
        foreach ($value as $entry) {
            if (is_scalar($entry)) {
                $text = $this->normalizeText((string)$entry);
                if ($text !== null && $text !== '') {
                    $items[] = $text;
                }
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $text = null;
            foreach (['name', 'title', 'text', 'value'] as $key) {
                if (isset($entry[$key]) && is_scalar($entry[$key])) {
                    $text = $this->normalizeText((string)$entry[$key]);
                    break;
                }
            }

            if ($text !== null && $text !== '') {
                $items[] = $text;
            }
        }

        return $this->uniqueTextItems($items);
    }

    private function composeList(array $items): ?string
    {
        $clean = [];
        foreach ($this->uniqueTextItems($items) as $item) {
            $text = rtrim($this->normalizeText((string)$item) ?? '', " \t\n\r\0\x0B;,");
            if ($text !== '') {
                $clean[] = $text;
            }
        }

        return $clean ? implode(";\n", $clean) : null;
    }

    private function composeInlineList(array $items): ?string
    {
        $clean = [];
        foreach ($this->uniqueTextItems($items) as $item) {
            $text = $this->normalizeText((string)$item);
            if ($text !== null && $text !== '') {
                $clean[] = $text;
            }
        }

        return $clean ? implode(', ', $clean) : null;
    }

    private function humanizeItems(array $items): array
    {
        return array_map(function (string $item) {
            return self::PROPERTY_VALUE_NAMES[$item] ?? $item;
        }, $items);
    }

    private function uniqueTextItems(array $items): array
    {
        $unique = [];
        foreach ($items as $item) {
            $text = $this->normalizeText((string)$item);
            if ($text === null || $text === '' || isset($unique[$text])) {
                continue;
            }

            $unique[$text] = $text;
        }

        return array_values($unique);
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/<\s*br\s*\/?>/iu', "\n", $text) ?? $text;
        $text = preg_replace('/<\/\s*(p|div|li|ul|ol|h[1-6]|section|article|tr)\s*>/iu', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function safeRequest(string $url, int $attempts = 5, int $delaySeconds = 3)
    {
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = Http::timeout(30)->get($url);

                if ($response->successful()) {
                    $json = $response->json();
                    if (is_array($json) || is_object($json)) {
                        return $json;
                    }
                    // Неправильный JSON
                    $this->warn("safeRequest: неверный JSON на попытке {$i} для {$url}");
                    Log::warning('GetVacancyByCurl: invalid json', ['url' => $url, 'body' => $response->body()]);
                } else {
                    $this->warn("Ошибка HTTP ({$response->status()}) на попытке {$i}: {$url}");
                    Log::warning('GetVacancyByCurl: http status', ['status' => $response->status(), 'url' => $url]);
                }
            } catch (\Throwable $e) {
                $this->warn("Ошибка на попытке {$i}: {$e->getMessage()}");
                Log::warning('GetVacancyByCurl: exception during request', ['attempt' => $i, 'url' => $url, 'exception' => $e->getMessage()]);
            }

            // экспоненциальная пауза (чтобы снизить нагрузку при проблемах)
            sleep($delaySeconds * $i);
        }

        $this->error("Не удалось получить данные после {$attempts} попыток: {$url}");
        Log::error('GetVacancyByCurl: request failed after attempts', ['url' => $url]);

        return null;
    }
}
