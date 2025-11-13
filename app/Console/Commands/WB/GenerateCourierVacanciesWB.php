<?php

namespace App\Console\Commands\WB;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateCourierVacanciesWB extends Command
{
    protected $signature = 'app:generate-courier-vacancies-w-b
        {--url=https://wbk.wb.ru/community-utils/api/feedback/city : URL JSON со списком городов (POST)}
        {--outfile=wb_courier_vacancies : Имя XLSX в storage/app (без .xlsx)}';

    protected $description = 'Генерирует один XLSX-файл с вакансиями курьеров (по одному городу на строку).';

    private const COLUMN_SCHEMA = [
        'city_id'       => 'ID города',
        'city_title'    => 'Город',
        'vacancy_title' => 'Название вакансии',
        'salary_from'   => 'Зарплата от',
        'salary_to'     => 'Зарплата до',
        'salary_text'   => 'Зарплата (текст)',
        'description'   => 'Описание',
    ];

    private const VACANCY_TITLE = 'Водитель категории CE (межскладская доставка)';
    private const SALARY_FROM   = null;
    private const SALARY_TO     = 250000;

    private const DESCRIPTION = <<<TXT
Вам предстоит

    доставлять грузы между складами и сортировочными центрами разных городов.

Почему стоит выбрать именно Wildberries

    Официальное трудоустройство с первого дня Выплачиваем «белую» зарплату, оплачиваем отпуска и больничные.
    Стабильный доход и прозрачная система выплат до 250 000 ₽ в месяц, доход зависит от протяжённости маршрута. Выплаты 2 раза в неделю — в понедельник и в четверг. Возможны подработки на коротких маршрутах.
    Новый и чистый автопарк Выдаём ключи от DongFeng, FAW, SITRAK, FOTON, JAC для комфортной работы. Все автомобили проходят ТО и оборудованы датчиками уровня топлива.
    Забота о сотрудниках Предоставляем дополнительные скидки на товары и услуги партнёров.
    Развитая корпоративная система поддержки Оплачиваем топливную карту, мойку и ТО.

Что мы ждём от вас:

    открытую категорию СE;
    стаж вождения на полуприцепах от 2 лет.
TXT;

    public function handle(): int
    {
        $url      = (string) $this->option('url');
        $outfile  = (string) $this->option('outfile');
        $outPath  = storage_path('app/' . $outfile . today() . '.xlsx');

        try {
            $this->info("Запрашиваю города (POST, empty body, no Content-Type): {$url}");

            // 1) максимально похожий на curl: пустое тело, без Content-Type
            $resp = Http::timeout(25)
                ->withHeaders([
                    'Accept'     => 'application/json, text/plain, */*',
                    'User-Agent' => 'curl/8.0', // мимикрия под curl помогает избежать странных проверок
                ])
                ->send('POST', $url, [
                    'body' => '',   // важно: пустое сырое тело
                    // не задаём Content-Type вообще
                ]);

            // 2) если всё ещё не ок — пробуем JSON {}
            if (!$resp->ok()) {
                $this->warn("Первый POST вернул {$resp->status()}. Пробую JSON {}...");

                $resp = Http::timeout(25)
                    ->withHeaders([
                        'Accept'       => 'application/json, text/plain, */*',
                        'User-Agent'   => 'curl/8.0',
                        // Content-Type установит asJson()
                    ])
                    ->asJson()
                    ->post($url, new \stdClass()); // {} а не []

                // 3) если снова не ок — пробуем как form-urlencoded (пустое)
                if (!$resp->ok()) {
                    $this->warn("JSON {} вернул {$resp->status()}. Пробую form-urlencoded...");
                    $resp = Http::timeout(25)
                        ->withHeaders([
                            'Accept'     => 'application/json, text/plain, */*',
                            'User-Agent' => 'curl/8.0',
                        ])
                        ->asForm()
                        ->post($url, []); // Content-Type: application/x-www-form-urlencoded; body пустой
                }
            }

            if (!$resp->ok()) {
                $this->error("HTTP {$resp->status()}");
                // для дебага покажем кусок тела ответа
                $body = mb_substr($resp->body() ?? '', 0, 500);
                $this->line("Ответ сервера (фрагмент): " . $body);
                return self::FAILURE;
            }

            $json   = $resp->json();
            $cities = $json['cities'] ?? null;

            if (!is_array($cities) || empty($cities)) {
                $this->warn('Пустой список cities — нечего писать.');
                return self::SUCCESS;
            }

            $rows = [];
            foreach ($cities as $c) {
                $id   = isset($c['id']) ? (string)$c['id'] : null;
                $name = isset($c['city']) ? (string)$c['city'] : null;
                if ($id === null || $name === null || $name === '') {
                    continue;
                }
                $rows[] = $this->makeVacancyRow($id, $name);
            }

            if (empty($rows)) {
                $this->warn('После нормализации данных строк нет.');
                return self::SUCCESS;
            }

            $this->writeXlsx($rows, $outPath);
            $this->info("Готово: {$outPath} (строк: " . count($rows) . ')');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function makeVacancyRow(string $cityId, string $cityTitle): array
    {
        return [
            'city_id'       => $cityId,
            'city_title'    => $cityTitle,
            'vacancy_title' => self::VACANCY_TITLE,
            'salary_from'   => self::SALARY_FROM,
            'salary_to'     => self::SALARY_TO,
            'salary_text'   => $this->formatSalary(self::SALARY_FROM, self::SALARY_TO),
            'description'   => self::DESCRIPTION,
        ];
    }

    private function formatSalary(?int $from, ?int $to): string
    {
        $fmt = fn(int $n) => number_format($n, 0, ',', ' ');
        if ($from && $to) return $fmt($from) . ' – ' . $fmt($to) . ' ₽';
        if ($to)          return 'до ' . $fmt($to) . ' ₽';
        if ($from)        return 'от ' . $fmt($from) . ' ₽';
        return '';
    }

    private function writeXlsx(array $rows, string $outPath): void
    {
        $columnKeys = array_keys(self::COLUMN_SCHEMA);
        $headers    = array_values(self::COLUMN_SCHEMA);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Courier Vacancies');

        // Заголовки
        $col = 1;
        foreach ($headers as $h) {
            $cell = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cell, $h);
            $col++;
        }

        // Данные
        $rowIdx = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($columnKeys as $key) {
                $cell = Coordinate::stringFromColumnIndex($col) . $rowIdx;
                $sheet->setCellValue($cell, $row[$key] ?? null);
                $col++;
            }
            $rowIdx++;
        }

        // Автоширина
        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save($outPath);
    }
}
