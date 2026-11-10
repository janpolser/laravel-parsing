<?php

namespace App\Console\Commands\VkusVill;

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
    protected $signature = 'vkusvill:collect-vacancies
        {--section=office : Раздел: office или all}
        {--max-pages=0 : Максимум страниц для preview, 0 — все}
        {--page-size=10 : Размер страницы API}
        {--sleep-ms=100 : Задержка между запросами деталей}
        {--outfile=vkusvill_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии ВкусВилл через публичный API и формирует YVL XML';

    private const LIST_URL = 'https://vkusvill.ru/job/office/';

    private const ALL_LIST_URL = 'https://vkusvill.ru/job/';

    private const API_URL = 'https://vkusvill.ru/ajax/job/hh_list_filter.php';

    private const HOST = 'vkusvill.ru';

    public function handle(YandexFeedXmlFormat $formatter): int
    {
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        $pageSize = filter_var($this->option('page-size'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');
        $section = (string) $this->option('section');
        if (! in_array($section, ['office', 'all'], true) || $maxPages === false || $maxPages < 0 || $pageSize === false || $pageSize < 1 || $pageSize > 100
            || $sleepMs === false || $sleepMs < 0 || $sleepMs > 10000 || ! preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }

        $all = $section === 'all';
        if ($all && $outfile === 'vkusvill_vacancies') {
            $outfile = 'vkusvill_all_vacancies';
        }
        $feed = $all ? 'vkusvill-all' : 'vkusvill';
        $defaultOutfile = $all ? 'vkusvill_all_vacancies' : 'vkusvill_vacancies';
        $preview = $maxPages > 0 || $pageSize !== 10 || $outfile !== $defaultOutfile;
        try {
            $listUrl = $all ? self::ALL_LIST_URL : self::LIST_URL;
            $page = $this->loadListPage($listUrl);
            $totalPages = $this->totalPages($page['data']['nav'] ?? '');
            $pages = $maxPages > 0 ? min($maxPages, $totalPages) : $totalPages;
            $cards = $this->cards($page['data']['vacancies'] ?? '');
            for ($current = 2; $current <= $pages; $current++) {
                $next = $this->requestPage($page, $current, $pageSize, $listUrl);
                $nextCards = $this->cards($next['data']['vacancies'] ?? '');
                $cards = array_merge($cards, $nextCards);
            }
            if ($cards === []) {
                throw new \RuntimeException('ВкусВилл: API не вернул вакансии');
            }
            $cardsByUrl = [];
            foreach ($cards as $card) {
                if (isset($cardsByUrl[$card['url']])) {
                    throw new \RuntimeException('ВкусВилл: повторная ссылка '.$card['url']);
                }
                $cardsByUrl[$card['url']] = $card;
            }

            $details = [];
            foreach (array_values($cardsByUrl) as $index => $card) {
                $response = Http::timeout(60)->connectTimeout(15)->retry(2, 500, throw: false)->get($card['url']);
                if (! $response->ok()) {
                    throw new \RuntimeException("ВкусВилл: HTTP {$response->status()} для {$card['url']}");
                }
                $details[] = [$card, $response->body()];
                if ($sleepMs > 0 && $index + 1 < count($cardsByUrl)) {
                    usleep($sleepMs * 1000);
                }
            }
            $cities = $all ? $this->cities($page['data']['cities'] ?? []) : [['ID' => '', 'NAME' => '']];
            if ($all && $cities === []) {
                throw new \RuntimeException('ВкусВилл: API не вернул доступные города');
            }
            $entities = [];
            foreach ($details as [$card, $html]) {
                foreach ($cities as $city) {
                    $entities[] = $this->mapVacancy($html, $card, $city['NAME'] ?: null, $city['ID']);
                }
            }
            $path = storage_path(($preview ? 'app/vkusvill-previews/' : 'app/public/vkusvill/').$outfile.today('Europe/Moscow')->toDateString().'.xml');
            if (! $preview) {
                $path = storage_path('app/public/'.$feed.'/'.$outfile.today('Europe/Moscow')->toDateString().'.xml');
            }
            File::ensureDirectoryExists(dirname($path));
            $formatter->createXmlFeed($entities, self::HOST, $path);
            if ($this->countXml($path) !== count($entities)) {
                throw new \RuntimeException('ВкусВилл: число записей XML не совпадает с результатом API');
            }
            $this->info("Страниц API: {$pages}/{$totalPages}; карточек: ".count($cardsByUrl).'; XML: '.count($entities));
            $this->info('XML сформирован: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор ВкусВилл не завершён; предыдущий фид сохранён: '.get_class($e).': '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{data:array<string,mixed>,cookies:array<string,string>} */
    private function loadListPage(string $listUrl): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0', 'Accept' => 'text/html'])
            ->timeout(60)->connectTimeout(15)->retry(2, 500, throw: false)->get($listUrl);
        if (! $response->ok()) {
            throw new \RuntimeException('ВкусВилл: HTTP '.$response->status().' на странице вакансий');
        }
        $dom = $this->dom($response->body());
        $sessid = trim((string) $this->xpath($dom)->query('//input[@name="sessid"]')->item(0)?->getAttribute('value'));
        if ($sessid === '') {
            throw new \RuntimeException('ВкусВилл: на странице отсутствует sessid');
        }
        $cookies = $this->cookies($response->cookies());
        $data = $this->requestPage(['sessid' => $sessid, 'cookies' => $cookies], 1, 10, $listUrl);

        return ['data' => $data['data'], 'cookies' => $cookies, 'sessid' => $sessid];
    }

    /** @param array{sessid:string,cookies:array<string,string>} $page */
    private function requestPage(array $page, int $number, int $pageSize, string $listUrl = self::LIST_URL): array
    {
        $response = Http::withCookies($page['cookies'], 'vkusvill.ru')->withHeaders([
            'User-Agent' => 'Mozilla/5.0', 'Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest',
            'Referer' => $listUrl,
        ])->timeout(60)->connectTimeout(15)->retry(2, 500, throw: false)->asForm()->post(self::API_URL, [
            'nextPage' => $number, 'pageSize' => $pageSize, 'searchText' => '', 'teamId' => '', 'cityId' => '',
            'action' => 'filterVacancies', 'sessid' => $page['sessid'],
        ]);
        if (! $response->ok() || $response->json('success') !== 'Y' || ! is_array($response->json('data'))) {
            throw new \RuntimeException('ВкусВилл: некорректный ответ API на странице '.$number);
        }

        return ['data' => $response->json('data')];
    }

    /** @return list<array{url:string,title:string,team:string,city:string}> */
    private function cards(string $html): array
    {
        $dom = $this->dom($html);
        $xpath = $this->xpath($dom);
        $cards = [];
        foreach ($xpath->query('//a[contains(@href,"/job/vacancys/")]') as $node) {
            $path = parse_url(trim((string) $node->getAttribute('href')), PHP_URL_PATH) ?: '';
            if (! preg_match('~^/job/vacancys/[A-Za-z0-9_-]+\.html$~', $path)) {
                continue;
            }
            $title = $this->text($xpath->query('.//*[contains(@class,"_Item_Name")]', $node)->item(0));
            $team = $this->text($xpath->query('.//*[contains(@class,"_team")]', $node)->item(0));
            $city = $this->text($xpath->query('.//*[contains(@class,"_city")]', $node)->item(0));
            if ($title === '') {
                throw new \RuntimeException('ВкусВилл: карточка без названия');
            }
            $cards[] = ['url' => 'https://vkusvill.ru'.$path, 'title' => $title, 'team' => $team, 'city' => $city];
        }

        return $cards;
    }

    private function mapVacancy(string $html, array $card, ?string $city = null, string $cityId = ''): array
    {
        $dom = $this->dom($html);
        $xpath = $this->xpath($dom);
        $root = $xpath->query('//div[contains(@class,"VV21_VacancyDetail")][1]')->item(0);
        $text = $this->lines($root ? $this->htmlText($dom, $root) : '');
        $title = $this->text($xpath->query('//h1[contains(@class,"VacancyDetail__Title")]')->item(0)) ?: $card['title'];
        $start = null;
        foreach ($text as $i => $line) {
            if (preg_match('/^ВкусВилл\s*[—-]/u', $line)) {
                $start = $i;
                break;
            }
        }
        $addressIndex = $start === null ? false : array_search('Адрес', array_slice($text, $start + 1), true);
        $addressIndex = $addressIndex === false ? false : $start + 1 + $addressIndex;
        $description = $start === null ? 'Актуальная вакансия ВкусВилл. Подробности доступны на странице вакансии.' : implode("\n", array_slice($text, $start, $addressIndex === false ? count($text) - $start : $addressIndex - $start));
        if ($city !== null) {
            $description = "Город трудоустройства: {$city}\n\n".$description;
        }
        $fields = VacancyTextUrls::extractFromVacancy(['description' => trim($description)]);
        $address = $city ?? ($addressIndex === false ? $card['city'] : ($text[$addressIndex + 1] ?? $card['city']));
        $url = $city !== null ? $card['url'].'?city_id='.rawurlencode($cityId).'&city='.rawurlencode($city) : $card['url'];
        $experience = '';
        foreach ($text as $line) {
            if (preg_match('/^Требуемый опыт(?: работы)?:\s*(.+)$/u', $line, $m)) {
                $experience = trim($m[1]);
                break;
            }
        }

        return array_filter([
            'url' => $url, 'mobile_url' => $url, 'creation_date' => Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3',
            'job_name' => $title, 'category' => ['industry' => $card['team']], 'description' => $fields['description'],
            'text_urls' => $fields['text_urls'] ?? [], 'requirement' => ['experience' => $experience ?: null],
            'addresses' => [['location' => $address]], 'company_name' => 'ВкусВилл', 'company_site' => 'https://vkusvill.ru/',
            'hr_agency' => false, 'campaign' => 'ВкусВилл',
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function dom(string $html): DOMDocument
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('ВкусВилл: некорректный HTML');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $dom;
    }

    private function xpath(DOMDocument $dom): DOMXPath
    {
        return new DOMXPath($dom);
    }

    private function htmlText(DOMDocument $dom, \DOMNode $node): string
    {
        $html = $dom->saveHTML($node);
        $html = preg_replace('~</(?:p|div|li|h[1-6]|br|section|article)>~iu', "\n", $html) ?? $html;
        $html = preg_replace('~<(?:script|style|form)[^>]*>.*?</(?:script|style|form)>~isu', '', $html) ?? $html;

        return html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

    /** @return array<string,string> */
    private function cookies($jar): array
    {
        $cookies = [];
        foreach ($jar->toArray() as $cookie) {
            if (isset($cookie['Name'], $cookie['Value'])) {
                $cookies[$cookie['Name']] = $cookie['Value'];
            }
        }

        return $cookies;
    }

    private function totalPages(string $nav): int
    {
        preg_match_all('/data-page="(\d+)"/', $nav, $matches);

        $pages = array_map('intval', $matches[1] ?? []);

        return max($pages ?: [1]);
    }

    /** @return list<array{ID:string,NAME:string}> */
    private function cities(mixed $cities): array
    {
        if (! is_array($cities)) {
            return [];
        }
        $result = [];
        foreach ($cities as $city) {
            $id = is_array($city) ? trim((string) ($city['ID'] ?? '')) : '';
            $name = is_array($city) ? trim((string) ($city['NAME'] ?? '')) : '';
            if ($id !== '' && $name !== '') {
                $result[$id] = ['ID' => $id, 'NAME' => $name];
            }
        }

        return array_values($result);
    }

    private function countXml(string $path): int
    {
        $xml = simplexml_load_file($path);

        return $xml === false ? 0 : count($xml->vacancies->vacancy ?? []);
    }
}
