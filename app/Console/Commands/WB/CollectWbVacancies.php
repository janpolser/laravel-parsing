<?php

namespace App\Console\Commands\WB;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use GuzzleHttp\Cookie\CookieJar;

class CollectWbVacancies extends Command
{
    
    protected $signature = 'wb:collect-wb-vacancies
        {--limit=500 : Размер страницы (макс. 1000)}
        {--start-offset=0 : С какого offset начинать}
        {--max-pages=1 : Сколько страниц тянуть (1 — только один запрос)}
        {--outfile=wb_vacancies.xlsx : Имя xlsx в storage/app}';

    protected $description = 'Собирает вакансии WB (career.wb.ru) и сохраняет в Excel (PhpSpreadsheet)';

    private const COLUMN_SCHEMA = [
        'id'                    => 'ID',
        'name'                  => 'Название',
        'direction_title'       => 'Направление',
        'direction_role_title'  => 'Роль',
        'experience_type_title' => 'Опыт',
        'city_title'            => 'Город',
        'employment_types'      => 'Тип занятости',
        'url' => 'Ссылка'
    ];


// опциональный прогрев, чтобы сервер сам выдал доп. куки с главной


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

        $jar = new CookieJar();

        // прогреваем главную страницу WB, чтобы получить валидные Set-Cookie
        $this->info('Прогреваю сессию career.wb.ru...');

        $warmup = Http::withOptions([
                'cookies' => $jar,
                'curl' => [
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                ],
            ])
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                'Accept'     => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Referer'    => 'https://career.wb.ru/',
                'Origin'     => 'https://career.wb.ru',
            ])
            ->get('https://career.wb.ru/');

        $this->info("Warmup status: {$warmup->status()}");

        // необязательно, но можно вывести какие куки были получены
        foreach ($jar->toArray() as $cookie) {
            $this->line("Cookie: {$cookie['Name']}={$cookie['Value']}");
        }

        $normalizedRows = [];
        $pagesFetched = 0;

        while ($pagesFetched < $maxPages) {
            $url = 'https://career.wb.ru/crm-api/api/v1/pub/vacancies';
            $resp = Http::withHeaders([
                    'Accept'     => 'application/json',
                    'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
                    'Referer'    => 'https://career.wb.ru/',
                    'Origin'     => 'https://career.wb.ru',
                ])
                ->withOptions([
                    'cookies' => $jar, // используем те же куки, полученные при прогреве
                    'curl' => [
                        CURLOPT_TIMEOUT => 20,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
                    ],
                ])
                ->get($url, ['limit' => $limit, 'offset' => $offset]);
                
            if (!$resp->ok()) {
                $this->error("HTTP {$resp->status()} при запросе {$url}?limit={$limit}&offset={$offset}");
                return self::FAILURE;
            }

            $json = $resp->json();
            // Подстраиваемся под фактическую структуру WB (items лежат в data.items)
            $items = [];
            if (isset($json['items']) && is_array($json['items'])) {
                $items = $json['items'];
            } elseif (isset($json['data']['items']) && is_array($json['data']['items'])) {
                $items = $json['data']['items'];
            } elseif (is_array($json)) {
                $items = $json;
            }

            if (!is_array($items) || empty($items)) {
                $this->info("Пусто на offset={$offset}. Останавливаюсь.");
                break;
            }

            // Нормализуем массив элементов (ожидаем массив объектов)
            foreach ($items as $row) {
                if (is_array($row)) {
                    $normalizedRows[] = $this->normalizeRow($row);
                }
            }

            $this->line("Страница: offset={$offset}, получено: ".count($items));
            $offset += $limit;
            $pagesFetched++;
        }

        if (empty($normalizedRows)) {
            $this->warn('Данных нет — писать нечего.');
            return self::SUCCESS;
        }

        $columnKeys = array_keys(self::COLUMN_SCHEMA);
        $headers    = array_values(self::COLUMN_SCHEMA);

        // Готовим Excel
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vacancies');

        // Записываем заголовки
        $col = 1;
        foreach ($headers as $h) {
            $cell = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cell, $h);
            $col++;
        }

        // Записываем строки
        $rowIdx = 2;
        foreach ($normalizedRows as $row) {
            $col = 1;
            foreach ($columnKeys as $key) {
                $cell = Coordinate::stringFromColumnIndex($col) . $rowIdx;
                $sheet->setCellValue($cell, $row[$key] ?? null);
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

    private function normalizeRow(array $item): array
    {
        $employmentTypes = [];
        if (isset($item['employment_types']) && is_array($item['employment_types'])) {
            foreach ($item['employment_types'] as $type) {
                if (is_array($type) && isset($type['title'])) {
                    $employmentTypes[] = $type['title'];
                }
            }
        }

        return [
            'id'                    => $item['id'] ?? null,
            'name'                  => $item['name'] ?? null,
            'direction_title'       => $item['direction_title'] ?? null,
            'direction_role_title'  => $item['direction_role_title'] ?? null,
            'experience_type_title' => $item['experience_type_title'] ?? null,
            'city_title'            => $item['city_title'] ?? null,
            'employment_types'      => empty($employmentTypes) ? null : implode(', ', $employmentTypes),
            'url' => 'https://career.wb.ru/vacancies/' . $item['id']
        ];
    }
}
