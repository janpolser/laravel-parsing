<?php

namespace App\Console\Commands\WB;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Cookie\CookieJar;

class CollectWbVacancies extends Command
{
    
    protected $signature = 'parser:collect-wb-vacancies
        {--limit=500 : Размер страницы (макс. 1000)}
        {--start-offset=0 : С какого offset начинать}
        {--max-pages=1 : Сколько страниц тянуть (1 — только один запрос)}
        {--outfile=wb_vacancies.xlsx : Имя xlsx в storage/app}';

    protected $description = 'Собирает вакансии WB (career.wb.ru) и сохраняет в Excel (PhpSpreadsheet)';

    $jar = CookieJar::fromArray([
    '_wbauid'        => '10374820771762851067',
    'popupDisplayed' => 'false',
], 'career.wb.ru');

// опциональный прогрев, чтобы сервер сам выдал доп. куки с главной
Http::withOptions([
        'cookies' => $jar,
        'curl' => [
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
        ],
    ])
    ->withHeaders([
        'User-Agent' => 'JobrateBot/1.0 (+https://jobrate.local)',
        'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Referer'    => 'https://career.wb.ru/',
        'Origin'     => 'https://career.wb.ru',
    ])
    ->get('https://career.wb.ru/');

    public function handle(): int
    {
        $limit       = (int) $this->option('limit');
        $offset      = (int) $this->option('start-offset');
        $maxPages    = (int) $this->option('max-pages');
        $outFileName = (string) $this->option('outfile');

        if ($limit < 1 || $limit > 1000) {
            $this->error('Параметр --limit должен быть в диапазоне 1..1000');
            return self::INVALID;
        }
        if ($maxPages < 1) {
            $this->error('Параметр --max-pages должен быть >= 1');
            return self::INVALID;
        }

        $all = [];
        $pagesFetched = 0;

        while ($pagesFetched < $maxPages) {
            $url = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies';
            $resp = Http::withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'JobrateBot/1.0 (+https://jobrate.local)',
                    'Cookie' => '_wbauid=10374820771762851067; popupDisplayed=false',
                ])
                // Если тебе принципиально “через cURL”, вот так прокидываются низкоуровневые опции:
                ->withOptions([
                    'curl' => [
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_FOLLOWLOCATION => true,
                    ],
                ])
                ->get($url, ['limit' => $limit, 'offset' => $offset]);

            if (!$resp->ok()) {
                $this->error("HTTP {$resp->status()} при запросе {$url}?limit={$limit}&offset={$offset}");
                return self::FAILURE;
            }

            $json = $resp->json();
            // Подстройся под фактическую структуру: чаще всего список в ключе 'items' или 'data'
            $items = $json['items'] ?? $json['data'] ?? (is_array($json) ? $json : []);

            if (!is_array($items) || empty($items)) {
                $this->info("Пусто на offset={$offset}. Останавливаюсь.");
                break;
            }

            // Нормализуем массив элементов (ожидаем массив объектов)
            foreach ($items as $row) {
                if (is_array($row)) {
                    $all[] = $this->flatten($row);
                }
            }

            $this->line("Страница: offset={$offset}, получено: ".count($items));
            $offset += $limit;
            $pagesFetched++;
        }

        if (empty($all)) {
            $this->warn('Данных нет — писать нечего.');
            return self::SUCCESS;
        }

        // Собираем заголовки как объединение ключей
        $headers = $this->collectHeaders($all);

        // Готовим Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vacancies');

        // Записываем заголовки
        $col = 1;
        foreach ($headers as $h) {
            $sheet->setCellValueByColumnAndRow($col, 1, $h);
            $col++;
        }

        // Записываем строки
        $rowIdx = 2;
        foreach ($all as $row) {
            $col = 1;
            foreach ($headers as $key) {
                $sheet->setCellValueByColumnAndRow($col, $rowIdx, $row[$key] ?? null);
                $col++;
            }
            $rowIdx++;
        }

        // Автоширина колонок (ок, при ~до сотен колонок; очень большие таблицы — отключи)
        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $outPath = storage_path('app/'.$outFileName);
        (new Xlsx($spreadsheet))->save($outPath);

        $this->info("Готово: {$outPath}");
        return self::SUCCESS;
    }

    /**
     * Плоское представление массива (dot-style), чтобы не гадать про вложенность
     */
    private function flatten(array $item, string $prefix = ''): array
    {
        $flat = [];
        foreach ($item as $k => $v) {
            $key = $prefix === '' ? (string)$k : "{$prefix}.{$k}";
            if (is_array($v)) {
                // Слишком глубокие/массивы объектов — ужимаем в JSON
                $isAssoc = Arr::isAssoc($v);
                if ($isAssoc) {
                    $flat = $flat + $this->flatten($v, $key);
                } else {
                    $flat[$key] = json_encode($v, JSON_UNESCAPED_UNICODE);
                }
            } else {
                // Нормализуем булевы/даты при необходимости
                $flat[$key] = is_bool($v) ? ($v ? 1 : 0) : $v;
            }
        }
        return $flat;
    }

    private function collectHeaders(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $k) {
                $keys[$k] = true;
            }
        }
        // Стабильный порядок: важные поля вперед, затем остальные по алфавиту
        $priority = [
            'id','title','name','department','city','location.city','salary.from','salary.to',
            'createdAt','updatedAt','publishedAt','url'
        ];
        $existing = array_keys($keys);
        $ordered = array_values(array_unique(array_merge(
            array_values(array_intersect($priority, $existing)),
            array_diff($existing, $priority)
        )));
        return $ordered;
    }
}
