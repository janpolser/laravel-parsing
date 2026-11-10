<?php

namespace App\Console\Commands\Monetka;

use App\Services\YandexFeedXmlFormat;
use App\Support\VacancyTextUrls;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class CollectVacancies extends Command
{
    protected $signature = 'monetka:collect-vacancies
        {--section=search : Раздел источника: search или pekarni}
        {--max-vacancies=0 : Максимум вакансий для preview, 0 — все найденные}
        {--sleep-ms=100 : Задержка между запросами деталей}
        {--outfile=monetka_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии Монетки с career.monetka.ru и формирует YVL XML';

    private const LIST_URL = 'https://career.monetka.ru/search/';

    private const BAKERY_LIST_URL = 'https://career.monetka.ru/pekarni/';

    public function handle(YandexFeedXmlFormat $formatter): int
    {
        $max = filter_var($this->option('max-vacancies'), FILTER_VALIDATE_INT);
        $sleep = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $section = (string) $this->option('section');
        $outfile = (string) $this->option('outfile');
        if (! in_array($section, ['search', 'pekarni'], true) || $max === false || $max < 0 || $sleep === false || $sleep < 0 || $sleep > 10000 || ! preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }
        $feed = $section === 'pekarni' ? 'monetka-pekarni' : 'monetka';
        if ($section === 'pekarni' && $outfile === 'monetka_vacancies') {
            $outfile = 'monetka_pekarni_vacancies';
        }
        $preview = $max > 0 || $outfile !== ($section === 'pekarni' ? 'monetka_pekarni_vacancies' : 'monetka_vacancies');
        try {
            $urls = $this->discoverUrls($section === 'pekarni' ? self::BAKERY_LIST_URL : self::LIST_URL);
            if ($max > 0) {
                $urls = array_slice($urls, 0, $max);
            }
            $entities = [];
            $missing = 0;
            foreach ($urls as $index => $url) {
                if ($index > 0 && $sleep > 0) {
                    usleep($sleep * 1000);
                }
                $response = Http::timeout(60)->connectTimeout(15)->retry(2, 500, throw: false)->get($url);
                if ($response->status() === 404) {
                    $missing++;

                    continue;
                }
                if (! $response->ok()) {
                    throw new \RuntimeException("Monetka: HTTP {$response->status()} для {$url}");
                }
                $entity = $this->mapVacancy($response->body(), $url);
                if ($entity === null) {
                    $missing++;

                    continue;
                }
                $entities[] = $entity;
            }
            if ($entities === []) {
                throw new \RuntimeException('Monetka: не найдено актуальных вакансий');
            }
            $path = storage_path(($preview ? 'app/monetka-previews/' : 'app/public/'.$feed.'/').$outfile.today('Europe/Moscow')->toDateString().'.xml');
            File::ensureDirectoryExists(dirname($path));
            $formatter->createXmlFeed($entities, 'career.monetka.ru', $path);
            if ($this->countXml($path) !== count($entities)) {
                throw new \RuntimeException('Monetka: неполный XML');
            }
            $this->info('Найдено ссылок: '.count($urls).'; в XML: '.count($entities).'; недоступных: '.$missing);
            $this->info('XML сформирован: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Monetka не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<string> */
    private function discoverUrls(string $listUrl): array
    {
        $response = Http::timeout(60)->connectTimeout(15)->retry(3, 1000, throw: false)->get($listUrl);
        if (! $response->ok()) {
            throw new \RuntimeException('Monetka: HTTP '.$response->status().' на странице списка');
        }
        $dom = $this->dom($response->body());
        $urls = [];
        foreach ((new DOMXPath($dom))->query('//a[@href]') as $node) {
            $path = parse_url(trim((string) $node->getAttribute('href')), PHP_URL_PATH) ?: '';
            if (preg_match('~^/vakansii/[A-Za-z0-9_-]+/?$~u', $path)) {
                $urls['https://career.monetka.ru'.rtrim($path, '/').'/'] = true;
            }
        }
        if ($urls === []) {
            throw new \RuntimeException('Monetka: не найдены ссылки /vakansii/');
        }

        return array_keys($urls);
    }

    /** @return array<string,mixed> */
    private function mapVacancy(string $html, string $url): ?array
    {
        $dom = $this->dom($html);
        $xpath = new DOMXPath($dom);
        $title = $this->text($xpath->query('//h1')->item(0));
        $body = $dom->getElementsByTagName('body')->item(0);
        $raw = $body ? $dom->saveHTML($body) : '';
        $raw = preg_replace('~</(?:p|div|li|h[1-6]|br|section|article)>~iu', "\n", $raw) ?? $raw;
        $lines = $this->lines(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '' || ! preg_match('/№\s*(\d+)/u', implode("\n", $lines), $id)) {
            $this->warn('Monetka: карточка недоступна или устарела '.$url);

            return null;
        }
        $descriptionStart = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^Монетка\s*[–—-]/u', $line)) {
                $descriptionStart = $i;
                break;
            }
        }
        $descriptionEnd = array_search('← Ко всем вакансиям', $lines, true);
        $description = $descriptionStart === null ? 'Актуальная вакансия ТС «Монетка». Подробности доступны на странице вакансии.' : implode("\n", array_slice($lines, $descriptionStart, ($descriptionEnd === false ? count($lines) : $descriptionEnd) - $descriptionStart));
        $description = $this->cleanDescription($description);
        $fields = VacancyTextUrls::extractFromVacancy(['description' => trim($description)]);
        $address = $this->after($lines, 'Адрес');
        $salary = $this->after($lines, 'Заработная плата');

        return array_filter([
            'url' => $url, 'mobile_url' => $url, 'creation_date' => Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3',
            'salary' => trim(preg_replace('/\s*₽\s*$/u', '', $salary)), 'currency' => $salary !== '' ? 'RUR' : null,
            'job_name' => $title, 'employment' => $this->after($lines, 'Тип занятости') ?: null,
            'description' => $fields['description'], 'text_urls' => $fields['text_urls'] ?? [],
            'requirement' => ['experience' => $this->after($lines, 'Опыт') ?: null],
            'addresses' => $address !== '' ? [['location' => $address]] : [], 'company_name' => 'ТС «Монетка»',
            'company_site' => 'https://career.monetka.ru/', 'hr_agency' => false, 'campaign' => 'Монетка',
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Monetka: некорректный HTML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private function text(?\DOMNode $node): string
    {
        return $node ? trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '') : '';
    }

    /** @return list<string> */
    private function lines(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return array_values(array_filter(array_map('trim', explode("\n", $text)), static fn ($line) => $line !== ''));
    }

    /** @param list<string> $lines */
    private function after(array $lines, string $label): string
    {
        $index = array_search($label, $lines, true);

        return $index === false ? '' : ($lines[$index + 1] ?? '');
    }

    private function cleanDescription(string $description): string
    {
        $description = preg_replace('/[_]{2,}|[-–—_]{5,}/u', "\n", $description) ?? $description;
        $description = strtr($description, [
            'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К', 'M' => 'М',
            'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х',
            'a' => 'а', 'c' => 'с', 'e' => 'е', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у',
        ]);
        $description = preg_replace('/[ \t]+/u', ' ', $description) ?? $description;
        $description = preg_replace('/ *\n */u', "\n", $description) ?? $description;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $description) ?? $description);
    }

    private function countXml(string $path): int
    {
        $xml = simplexml_load_file($path);

        return $xml === false ? 0 : count($xml->vacancies->vacancy ?? []);
    }
}
