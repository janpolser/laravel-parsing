<?php

namespace App\Console\Commands\KrasnoeBeloe;

use App\Services\YandexFeedXmlFormat;
use App\Support\FeedCountTolerance;
use App\Support\VacancyTextUrls;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use XMLReader;

class CollectVacancies extends Command
{
    protected $signature = 'krasnoeibeloe:collect-vacancies
        {--max-cities=0 : Максимум городов для preview, 0 — вся страна}
        {--batch-size=2 : Размер пакета городов, запросы выполняются последовательно}
        {--sleep-ms=150 : Задержка между пачками запросов}
        {--outfile=krasnoeibeloe_vacancies : Базовое имя XML}';

    protected $description = 'Собирает вакансии Красное&Белое по всей стране';

    private const HOST = 'https://krasnoeibeloe.ru';

    private const DISCOVERY = self::HOST.'/job/chelyabinsk/';

    private const API = self::HOST.'/api/v2/vacancies/list/';

    private const CACHE_VERSION = 1;

    private const CACHE_TTL = 21600;

    private int $seen = 0;

    private array $seenUrls = [];

    private string $lastError = '';

    public function handle(YandexFeedXmlFormat $formatter): int
    {
        $maxCities = filter_var($this->option('max-cities'), FILTER_VALIDATE_INT);
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');
        if ($maxCities === false || $maxCities < 0 || $batchSize === false || $batchSize < 1 || $batchSize > 4
            || $sleepMs === false || $sleepMs < 0 || $sleepMs > 10000 || !preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }

        $preview = $maxCities > 0 || $outfile !== 'krasnoeibeloe_vacancies';
        $cacheDir = storage_path('app/krasnoeibeloe-cache');
        File::ensureDirectoryExists($cacheDir);
        $lock = fopen($cacheDir.'/collect.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            $this->error('Сбор Красное&Белое уже выполняется');
            if (is_resource($lock)) {
                fclose($lock);
            }

            return self::FAILURE;
        }

        try {
            $this->seen = 0;
            $this->seenUrls = [];
            $allCities = $this->discoverCities();
            $cities = $maxCities > 0 ? array_slice($allCities, 0, $maxCities) : $allCities;
            $expected = array_sum(array_column($cities, 'count'));
            $this->info('Справочник: '.count($allCities).' городов; в запуске: '.count($cities)."; ожидается вакансий: {$expected}");

            $stageDir = storage_path('app/krasnoeibeloe-stage');
            File::ensureDirectoryExists($stageDir);
            $stagePath = $stageDir.'/'.$outfile.today()->toDateString().'.xml';
            $formatter->createXmlFeed($this->entities($cities, $cacheDir, $batchSize, $sleepMs), 'krasnoeibeloe.ru', $stagePath);
            if ($this->seen < 1 || $this->countXmlVacancies($stagePath) !== $this->seen) {
                throw new \RuntimeException('Число вакансий в XML не совпадает с полученными карточками');
            }

            $targetDir = storage_path($preview ? 'app/krasnoeibeloe-previews' : 'app/public/krasnoeibeloe');
            File::ensureDirectoryExists($targetDir);
            $target = $targetDir.'/'.$outfile.today()->toDateString().'.xml';
            if (!rename($stagePath, $target)) {
                throw new \RuntimeException('Не удалось опубликовать проверенный XML');
            }
            $this->info("XML сформирован: {$target}; вакансий: {$this->seen}");

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Красное&Белое не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array{region:string,city:string,count:int}> */
    private function discoverCities(): array
    {
        $response = Http::timeout(90)->connectTimeout(15)->retry(3, 1000, throw: false)->get(self::DISCOVERY);
        if (!$response->ok()) {
            throw new \RuntimeException('Ошибка страницы справочника: HTTP '.$response->status());
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadHTML('<?xml encoding="UTF-8">'.$response->body(), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Некорректный HTML справочника');
            }
            $node = (new DOMXPath($dom))->query('//vacancy-filter')->item(0);
            if (!$node) {
                throw new \RuntimeException('На странице не найден справочник городов');
            }
            $counts = json_decode($node->getAttribute(':count-data'), true, 512, JSON_THROW_ON_ERROR);
            $names = json_decode($node->getAttribute(':names-map'), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!is_array($counts) || !is_array($names)) {
            throw new \RuntimeException('Некорректный справочник регионов');
        }

        $cities = [];
        foreach ($counts as $regionId => $regionCities) {
            if ($regionId === 'any') {
                continue;
            }
            if (!is_array($regionCities) || !is_string($names[$regionId] ?? null) || trim($names[$regionId]) === '') {
                throw new \RuntimeException('Некорректный регион справочника');
            }
            $regionTotal = $regionCities['any']['any']['any']['any'] ?? null;
            $sum = 0;
            foreach ($regionCities as $cityId => $values) {
                if ($cityId === 'any') {
                    continue;
                }
                $count = is_array($values) ? ($values['any']['any']['any'] ?? null) : null;
                if (!is_string($names[$cityId] ?? null) || trim($names[$cityId]) === ''
                    || (!is_int($count) && !(is_string($count) && ctype_digit($count))) || (int) $count < 1) {
                    throw new \RuntimeException('Некорректный город или количество вакансий в справочнике');
                }
                $cities[] = ['region' => $names[$regionId], 'city' => $names[$cityId], 'count' => (int) $count];
                $sum += (int) $count;
            }
            if ((!is_int($regionTotal) && !(is_string($regionTotal) && ctype_digit($regionTotal))) || ! FeedCountTolerance::allows((int) $regionTotal, $sum)) {
                throw new \RuntimeException('Количество вакансий региона не совпадает с суммой городов: '.$names[$regionId]);
            }
        }
        if ($cities === []) {
            throw new \RuntimeException('Справочник городов пуст');
        }
        usort($cities, static fn ($a, $b) => [$a['region'], $a['city']] <=> [$b['region'], $b['city']]);
        $keys = array_map(static fn ($city) => $city['region']."\0".$city['city'], $cities);
        if (count(array_unique($keys)) !== count($keys)) {
            throw new \RuntimeException('Повторный город в справочнике');
        }

        return $cities;
    }

    private function entities(array $cities, string $cacheDir, int $batchSize, int $sleepMs): \Generator
    {
        $processed = 0;
        $differenceTotal = 0;
        foreach (array_chunk($cities, $batchSize) as $batch) {
            $responses = [];
            $needed = [];
            foreach ($batch as $index => $city) {
                $cached = $this->readCache($cacheDir, $city);
                if ($cached !== null) {
                    $responses[$index] = $cached;
                } else {
                    $needed[$index] = $city;
                }
            }
            if ($needed !== []) {
                foreach ($needed as $index => $city) {
                    try {
                        $response = Http::timeout(120)->connectTimeout(15)->acceptJson()
                            ->get(self::API, ['region' => $city['region'], 'city' => $city['city']]);
                        $entities = $this->parseResponse($response, $city, true);
                    } catch (\Throwable) {
                        $entities = null;
                    }
                    for ($attempt = 1; $entities === null && $attempt <= 3; $attempt++) {
                        $this->warn("Повтор города {$city['region']}, {$city['city']}: попытка {$attempt}");
                        sleep($attempt);
                        try {
                            $response = Http::timeout(120)->connectTimeout(15)->acceptJson()
                                ->get(self::API, ['region' => $city['region'], 'city' => $city['city']]);
                            $entities = $this->parseResponse($response, $city, true);
                        } catch (\Throwable) {
                            $entities = null;
                        }
                    }
                    if ($entities === null) {
                        throw new \RuntimeException("Неполный или недоступный ответ: {$city['region']}, {$city['city']}; ожидалось {$city['count']}; {$this->lastError}");
                    }
                    $verified = false;
                    if (count($entities) !== $city['count'] && (FeedCountTolerance::allows($city['count'], count($entities))
                        || ($entities === [] && $city['count'] <= 10))) {
                        $this->warn("Небольшое расхождение счётчика: {$city['region']}, {$city['city']}; счётчик {$city['count']}, API ".count($entities));
                        $verified = true;
                    } elseif (count($entities) !== $city['count']) {
                        throw new \RuntimeException("Расхождение счётчика превышает допуск: {$city['region']}, {$city['city']}");
                    }
                    $this->writeCache($cacheDir, $city, $entities, $verified);
                    $responses[$index] = $entities;
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }
            ksort($responses);
            foreach ($responses as $index => $entities) {
                $differenceTotal += abs(count($entities) - $batch[$index]['count']);
                if ($differenceTotal > 10) {
                    throw new \RuntimeException('Суммарное расхождение по городам превышает 10 вакансий');
                }
                foreach ($entities as $entity) {
                    if (isset($this->seenUrls[$entity['url']])) {
                        throw new \RuntimeException('Повторная ссылка вакансии: '.$entity['url']);
                    }
                    $this->seenUrls[$entity['url']] = true;
                    $this->seen++;
                    yield $entity;
                }
                $processed++;
                if ($processed % 25 === 0 || $processed === count($cities)) {
                    $this->line('Городов: '.$processed.'/'.count($cities).'; вакансий: '.$this->seen);
                }
            }
        }
    }

    private function parseResponse(mixed $response, array $city, bool $allowEmpty = false): ?array
    {
        if (!$response instanceof Response || !$response->ok()) {
            $this->lastError = $response instanceof Response ? 'HTTP '.$response->status() : 'ошибка соединения';

            return null;
        }
        $json = $response->json();
        $items = is_array($json) ? ($json['data']['items'] ?? null) : null;
        $path = is_array($json) ? ($json['data']['url'] ?? null) : null;
        if ($path === '/job//') {
            $path = '/job/';
        }
        if (($json['success'] ?? null) !== true || !is_array($items) || !array_is_list($items)
            || (!$allowEmpty && $items === []) || !is_string($path)
            || !preg_match('~^/job/(?:[a-zA-Z0-9_-]+/)?$~', $path)) {
            $this->lastError = 'некорректные метаданные или количество: '.(is_array($items) ? count($items) : 'нет списка').'; путь: '.(is_string($path) ? $path : 'не указан');

            return null;
        }
        $entities = [];
        try {
            foreach ($items as $item) {
                $entities[] = $this->mapVacancy($item, $path, $city);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();

            return null;
        }

        return $entities;
    }

    private function mapVacancy(mixed $item, string $path, array $city): array
    {
        $vacancy = is_array($item) ? ($item['vacancy'] ?? null) : null;
        $link = is_array($item) ? ($item['link'] ?? null) : null;
        if (!is_array($vacancy) || !is_string($vacancy['position'] ?? null) || trim($vacancy['position']) === ''
            || !is_string($link) || !preg_match('~^vacancy/\?vacancy=[a-zA-Z0-9_+%=/\-]+$~', $link)
            || !is_string($item['city'] ?? null) || trim($item['city']) !== $city['city']
            || !is_string($item['region'] ?? null) || trim($item['region']) !== $city['region']
            || !is_array($vacancy['items'] ?? null)) {
            throw new \RuntimeException('Некорректная карточка вакансии');
        }
        $parts = [];
        $sections = [];
        foreach ($vacancy['items'] as $section) {
            if (!is_array($section) || !is_string($section['title'] ?? null) || !is_array($section['parts'] ?? null)) {
                throw new \RuntimeException('Некорректные разделы вакансии');
            }
            $lines = [];
            foreach ($section['parts'] as $text) {
                if (!is_string($text)) {
                    throw new \RuntimeException('Некорректный текст вакансии');
                }
                $text = $this->clean($text);
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
            if ($lines !== []) {
                $title = $this->clean($section['title']);
                $value = implode("\n", $lines);
                $parts[] = ($title !== '' ? $title.":\n" : '').$value;
                $sections[$title] = $value;
            }
        }
        $description = VacancyTextUrls::extractFromVacancy(['description' => implode("\n\n", $parts)]);
        if (empty($description['description'])) {
            throw new \RuntimeException('Нет описания вакансии');
        }
        $salary = is_array($vacancy['salary'] ?? null) ? $vacancy['salary'] : [];
        $from = isset($salary['from']) && is_scalar($salary['from']) ? $this->clean((string) $salary['from']) : '';
        $to = isset($salary['to']) && is_scalar($salary['to']) ? $this->clean((string) $salary['to']) : '';
        $salaryText = trim(($from !== '' && preg_match('/\d/u', $from) ? 'от '.$from : '').($to !== '' && preg_match('/\d/u', $to) ? ' до '.$to : ''));
        $location = implode(', ', array_filter([$city['region'], $city['city'], is_string($item['address'] ?? null) ? $this->clean($item['address']) : '']));
        $address = ['location' => $location];
        if (is_numeric($item['lat'] ?? null) && is_numeric($item['lon'] ?? null)
            && abs((float) $item['lat']) > 0 && abs((float) $item['lat']) <= 90
            && abs((float) $item['lon']) > 0 && abs((float) $item['lon']) <= 180) {
            $address['lat'] = $item['lat'];
            $address['lng'] = $item['lon'];
        }
        if (is_array($item['metro'] ?? null)) {
            $address['metro'] = array_values(array_filter($item['metro'], 'is_string'));
        }
        $now = Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3';

        $url = $this->canonicalUrl($link);

        return array_filter([
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => $now,
            'job_name' => $this->clean($vacancy['position']),
            'description' => $description['description'],
            'text_urls' => $description['text_urls'] ?? [],
            'salary' => $salaryText ?: null,
            'currency' => $salaryText !== '' ? 'RUR' : null,
            'schedule' => $sections['Режим работы'] ?? null,
            'duty' => $sections['Функционал'] ?? null,
            'requirement' => ['experience' => $sections['Опыт'] ?? null, 'qualification' => $sections['Пожелания'] ?? null],
            'category' => ['industry' => is_string($vacancy['department'] ?? null) ? $this->clean($vacancy['department']) : null],
            'addresses' => [$address],
            'company_name' => 'Красное&Белое',
            'company_site' => self::HOST,
            'hr_agency' => false,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
    }

    private function clean(string $value): string
    {
        $value = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|div|li|ul|ol)\s*>/iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value) ?? '';
        $value = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value);
    }

    private function cachePath(string $dir, array $city): string
    {
        return $dir.'/'.hash('sha256', $city['region']."\0".$city['city']).'.json';
    }

    private function canonicalUrl(string $link): string
    {
        $prefix = 'vacancy/?vacancy=';
        if (!str_starts_with($link, $prefix) || !preg_match('~^vacancy/\?vacancy=[a-zA-Z0-9_+%=/\-]+$~', $link)) {
            throw new \RuntimeException('Некорректная ссылка вакансии');
        }

        return self::HOST.'/job/vacancy/?vacancy='.rawurlencode(rawurldecode(substr($link, strlen($prefix))));
    }

    private function readCache(string $dir, array $city): ?array
    {
        $path = $this->cachePath($dir, $city);
        if (!is_file($path) || filemtime($path) < time() - self::CACHE_TTL) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['version'] ?? null) !== self::CACHE_VERSION || ($data['city'] ?? null) !== $city
            || !is_array($data['entities'] ?? null) || !array_is_list($data['entities'])
            || (count($data['entities']) !== $city['count'] && ($data['partitionVerified'] ?? null) !== true)) {
            return null;
        }
        foreach ($data['entities'] as &$entity) {
            if (!is_array($entity) || empty($entity['url']) || empty($entity['job_name']) || empty($entity['description'])) {
                return null;
            }
            if (!preg_match('~^https://krasnoeibeloe\.ru/job/(?:[a-zA-Z0-9_-]+/)?(vacancy/\?vacancy=[a-zA-Z0-9_+%=/\-]+)$~', $entity['url'], $matches)) {
                return null;
            }
            $entity['url'] = $this->canonicalUrl($matches[1]);
            $entity['mobile_url'] = $entity['url'];
        }
        unset($entity);

        return $data['entities'];
    }

    private function writeCache(string $dir, array $city, array $entities, bool $verified): void
    {
        $tmp = tempnam($dir, 'kb-');
        if ($tmp === false) {
            throw new \RuntimeException('Не удалось создать временный файл контрольной точки');
        }
        try {
            $data = json_encode(['version' => self::CACHE_VERSION, 'city' => $city, 'entities' => $entities, 'partitionVerified' => $verified], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $this->cachePath($dir, $city))) {
                throw new \RuntimeException('Не удалось сохранить контрольную точку');
            }
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    private function countXmlVacancies(string $path): int
    {
        $reader = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        $count = 0;
        try {
            if (!$reader->open($path, null, LIBXML_NONET)) {
                throw new \RuntimeException('Не удалось открыть XML для проверки');
            }
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'vacancy') {
                    $count++;
                }
            }
            if (libxml_get_errors() !== []) {
                throw new \RuntimeException('XML содержит ошибки');
            }

            return $count;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
