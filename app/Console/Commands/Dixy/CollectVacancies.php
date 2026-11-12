<?php

namespace App\Console\Commands\Dixy;

use App\Services\YandexFeedXmlFormat;
use App\Support\FeedCountTolerance;
use App\Support\VacancyTextUrls;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

class CollectVacancies extends Command
{
    protected $signature = 'dixy:collect-vacancies
        {--max-pages=0 : Максимум страниц для preview, 0 — все}
        {--sleep-ms=100 : Задержка между запросами деталей}
        {--outfile=dixy_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии Dixy через публичный API и формирует YVL XML';

    private const API_URL = 'https://dixy.ru/ajax/vacancies-json.php';

    private const HOST = 'https://dixy.ru';

    public function handle(YandexFeedXmlFormat $formatter): int
    {
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');
        if ($maxPages === false || $maxPages < 0 || $sleepMs === false || $sleepMs < 0 || $sleepMs > 10000
            || ! preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }

        $preview = $maxPages > 0 || $outfile !== 'dixy_vacancies';
        try {
            $rows = $this->collectRows($maxPages);
            $this->info('Вакансий API: '.count($rows));
            $entities = [];
            $missing = 0;
            foreach ($rows as $index => $row) {
                if ($index > 0 && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
                $url = self::HOST.'/group/career/vacancy/'.$row['id'].'/';
                $response = Http::timeout(60)->connectTimeout(15)->retry(2, 500, throw: false)->get($url);
                if ($response->status() === 404) {
                    $missing++;
                    $this->warn('Вакансия недоступна: '.$url);

                    continue;
                }
                if (! $response->ok()) {
                    throw new \RuntimeException("Dixy: HTTP {$response->status()} для {$url}");
                }
                $entities[] = $this->mapVacancy($row, $response->body(), $url);
            }
            if ($entities === []) {
                throw new \RuntimeException('Dixy: не найдено актуальных вакансий');
            }

            $path = storage_path(($preview ? 'app/dixy-previews/' : 'app/public/dixy/').$outfile.today('Europe/Moscow')->toDateString().'.xml');
            File::ensureDirectoryExists(dirname($path));
            $formatter->createXmlFeed($entities, 'dixy.ru', $path);
            if ($this->countXml($path) !== count($entities)) {
                throw new \RuntimeException('Dixy: число записей XML не совпадает с результатом разбора');
            }
            $this->info('В XML: '.count($entities).'; недоступных деталей: '.$missing);
            $this->info('XML сформирован: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Dixy не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /** @return list<array{id:string,name:string,city:string,type:string,schedule:string}> */
    private function collectRows(int $maxPages): array
    {
        $page = 1;
        $totalPages = null;
        $totalItems = null;
        $duplicates = 0;
        $rows = [];
        do {
            $response = Http::acceptJson()->timeout(45)->connectTimeout(15)->retry(3, 700, throw: false)
                ->get(self::API_URL, ['page' => $page, 'city' => '', 'direction' => '', 'position' => '', 'employment' => '']);
            if (! $response->ok()) {
                throw new \RuntimeException("Dixy API: HTTP {$response->status()} на странице {$page}");
            }
            $body = $response->json();
            $items = is_array($body) ? ($body['vacancies'] ?? null) : null;
            $paging = is_array($body) ? ($body['pagenData'] ?? null) : null;
            if (! is_array($items) || ! array_is_list($items) || ! is_array($paging)) {
                throw new \RuntimeException("Dixy API: некорректный ответ страницы {$page}");
            }
            $currentPage = $this->positiveInt($paging['page'] ?? null, 'page');
            $pages = $this->positiveInt($paging['pages_count'] ?? null, 'pages_count');
            $count = $this->positiveInt($paging['element_count'] ?? null, 'element_count');
            if ($currentPage !== $page || $pages < $page || count($items) < 1 || count($items) > 7) {
                throw new \RuntimeException("Dixy API: противоречивая пагинация на странице {$page}");
            }
            $totalPages ??= $pages;
            $totalItems ??= $count;
            if (abs($totalPages - $pages) > 1 || ! FeedCountTolerance::allows($totalItems, $count)) {
                throw new \RuntimeException('Dixy API: каталог изменился во время обхода');
            }
            $totalPages = $pages;
            $beforePage = count($rows);
            foreach ($items as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (! is_array($item) || ! is_string($id) || ! ctype_digit($id)) {
                    throw new \RuntimeException("Dixy API: некорректная вакансия на странице {$page}");
                }
                if (isset($rows[$id])) {
                    $duplicates++;

                    continue;
                }
                $rows[$id] = [
                    'id' => $id, 'name' => trim((string) ($item['name'] ?? '')),
                    'city' => trim((string) ($item['city'] ?? '')), 'type' => trim((string) ($item['type'] ?? '')),
                    'schedule' => trim((string) ($item['schedule'] ?? '')),
                ];
                if ($rows[$id]['name'] === '') {
                    throw new \RuntimeException("Dixy API: пустое название вакансии {$id}");
                }
            }
            if (count($rows) === $beforePage) {
                throw new \RuntimeException("Dixy API: страница {$page} не содержит новых вакансий");
            }
            $page++;
        } while ($page <= ($maxPages > 0 ? min($maxPages, $totalPages) : $totalPages));

        if ($maxPages === 0 && ! FeedCountTolerance::allows($totalItems, count($rows))) {
            throw new \RuntimeException('Dixy API: число элементов не совпадает с element_count');
        }

        return array_values($rows);
    }

    /** @param array{id:string,name:string,city:string,type:string,schedule:string} $row */
    private function mapVacancy(array $row, string $html, string $url): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
                throw new \RuntimeException('Dixy: некорректный HTML '.$url);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($dom);
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($xpath->query('//h1[contains(@class,"hr-vacancy__title")]')->item(0)?->textContent ?? '')) ?? '');
        $contentNode = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " hr-vacancy__list ")]')->item(0);
        $content = $contentNode ? $this->nodeText($contentNode, $dom) : '';
        if ($title === '') {
            $title = $row['name'];
        }
        if ($content === '') {
            throw new \RuntimeException('Dixy: пустое описание '.$url);
        }
        $descriptionFields = VacancyTextUrls::extractFromVacancy(['description' => $content]);

        return array_filter([
            'url' => $url, 'mobile_url' => $url,
            'creation_date' => Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3',
            'job_name' => $title, 'schedule' => $row['schedule'] ?: null,
            'description' => $descriptionFields['description'], 'text_urls' => $descriptionFields['text_urls'] ?? [],
            'category' => ['industry' => $row['type'] ?: null],
            'addresses' => $row['city'] !== '' ? [['location' => $row['city']]] : [],
            'company_name' => 'ДИКСИ', 'company_site' => self::HOST,
            'hr_agency' => false, 'campaign' => 'ДИКСИ #'.$row['id'],
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function nodeText(\DOMNode $node, DOMDocument $dom): string
    {
        $html = $dom->saveHTML($node);
        $html = preg_replace('~</(?:p|div|li|h[1-6]|br|ul|ol)>~iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $text)) ?? $text;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }

    private function positiveInt(mixed $value, string $field): int
    {
        if ((! is_int($value) && ! is_string($value)) || ! ctype_digit((string) $value) || (int) $value < 1) {
            throw new \RuntimeException("Dixy API: некорректное поле {$field}");
        }

        return (int) $value;
    }

    private function countXml(string $path): int
    {
        $xml = simplexml_load_file($path);

        return $xml === false ? 0 : count($xml->vacancies->vacancy ?? []);
    }
}
