<?php

namespace App\Console\Commands\RZHD;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CollectVacancies extends Command
{
    protected $signature = 'rzhd:collect-vacancies {--outfile=rzhd_vacancies.xlsx : Имя xlsx в storage/app}';
    protected $description = 'Собирает вакансии с team.rzd.ru и сохраняет в Excel';

    private const COLUMN_SCHEMA = [
        'id'            => 'ID',
        'position_id'   => 'Position ID',
        'salary_from'   => 'Зарплата от',
        'salary_to'     => 'Зарплата до',
        'salary_month'  => 'Зарплата в месяц',
        'schedule'      => 'График',
        'experience'    => 'Опыт',
        'employment_type'=> 'Тип занятости',
        'status'        => 'Статус',
        'published_at'  => 'Дата публикации',
        'locality_id'   => 'ID города',
        'locality_name' => 'Город',
        'direction_title'=> 'Направление',
        'speciality_title'=> 'Специальность',
        'url' => 'Ссылка'
    ];

    public function handle(): int
    {
        $outFileName = (string)$this->option('outfile');

        $baseUrl = 'https://team.rzd.ru/api/v1/career/vacancies';

        // 1 — первичный запрос для получения count
        $this->info('Запрос для получения общего количества вакансий...');
        $resp1 = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Accept'     => 'application/json, text/plain, */*',
            'Origin'     => 'https://team.rzd.ru',
            'Referer'    => 'https://team.rzd.ru/',
        ])
        ->get($baseUrl, [
            'page'     => 1,
            'per_page' => 1,
            'query'    => 'п',
            'sort'     => 'date_desc',
        ]);

        if (!$resp1->ok()) {
            $this->error('Ошибка первичного запроса: HTTP ' . $resp1->status());
            return self::FAILURE;
        }

        $json1 = $resp1->json();
        $count = $json1['meta']['count'] ?? null;

        if (!$count || !is_numeric($count)) {
            $this->error('Не удалось получить meta.count');
            return self::FAILURE;
        }

        $this->info("Всего вакансий: {$count}");

        // 2 — запрос всех вакансий
        $this->info('Запрашиваю полный список...');
        $resp2 = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0',
            'Accept'     => 'application/json, text/plain, */*',
            'Origin'     => 'https://team.rzd.ru',
            'Referer'    => 'https://team.rzd.ru/',
        ])
        ->get($baseUrl, [
            'page'     => 1,
            'per_page' => $count,
            'query'    => 'п',
            'sort'     => 'date_desc',
        ]);

        if (!$resp2->ok()) {
            $this->error('Ошибка запроса вакансий: HTTP ' . $resp2->status());
            return self::FAILURE;
        }

        $json2 = $resp2->json();
        $items = $json2['data'] ?? [];

        if (empty($items)) {
            $this->warn('Вакансии не найдены.');
            return self::SUCCESS;
        }

        // Нормализуем строки
        $normalized = [];
        foreach ($items as $item) {
            $normalized[] = $this->normalizeRow($item);
        }

        // Подготовка Excel
        $keys    = array_keys(self::COLUMN_SCHEMA);
        $headers = array_values(self::COLUMN_SCHEMA);

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vacancies');

        // Заголовки
        $col = 1;
        foreach ($headers as $h) {
            $cell = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cell, $h);
            $col++;
        }

        // Строки
        $rowIdx = 2;
        foreach ($normalized as $row) {
            $col = 1;
            foreach ($keys as $key) {
                $cell = Coordinate::stringFromColumnIndex($col) . $rowIdx;
                $sheet->setCellValue($cell, $row[$key] ?? null);
                $col++;
            }
            $rowIdx++;
        }

        // Автоширина колонок
        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        $outPath = storage_path('app/' . $outFileName);
        (new Xlsx($spreadsheet))->save($outPath);

        $this->info("Готово: {$outPath}");
        return self::SUCCESS;
    }

    private function normalizeRow(array $item): array
    {
        return [
            'id'              => $item['id'] ?? null,
            'position_id'     => $item['position_id'] ?? null,
            'salary_from'     => $item['salary_from'] ?? null,
            'salary_to'       => $item['salary_to'] ?? null,
            'salary_month'    => $item['salary_month'] ?? null,
            'schedule'        => $item['schedule'] ?? null,
            'experience'      => $item['experience'] ?? null,
            'employment_type' => $item['employment_type'] ?? null,
            'status'          => $item['status'] ?? null,
            'published_at'    => $item['published_at'] ?? null,
            'locality_id'     => $item['locality_id'] ?? null,
            'locality_name'   => $item['locality_name'] ?? null,
            'direction_title' => $item['direction_title'] ?? null,
            'speciality_title'=> $item['speciality_title'] ?? null,
            'url' => 'team.rzd.ru/career/vacancies/' . $item['id']
        ];
    }
}
