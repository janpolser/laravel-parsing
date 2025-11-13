<?php

namespace App\Console\Commands\WB;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class GenerateVacanciesWB extends Command
{
    protected $signature = 'wb:generate-city-vacancies
        {--js-url=https://career.wb.ru/assets/index-BhyYQxGx.js : URL JS-ассета с переменной lL=[...]}
        {--outfile=wb_city_vacancies : Имя xlsx в storage/app (без .xlsx)}';

    protected $description = 'Извлекает список городов из JS (переменная lL) и создаёт вакансии по каждому городу, сохраняя в Excel.';

    // Колонки выходного XLSX
    private const COLUMN_SCHEMA = [
        'city_id'       => 'ID города (value)',
        'city_title'    => 'Город',
        'vacancy_title' => 'Название вакансии',
        'salary_from'   => 'Зарплата от',
        'salary_to'     => 'Зарплата до',
        'salary_text'   => 'Зарплата (текст)',
        'description'   => 'Описание',
        'url' => 'Ссылка'
    ];

    // Фиксированные поля вакансии по ТЗ
    private const VACANCY_TITLE = 'Исполнитель услуг склада (упаковка/сортировка/разгрузка)';
    private const SALARY_FROM   = 110000;
    private const SALARY_TO     = 200000;

    private const DESCRIPTION = <<<TXT
Wildberries — крупнейший маркетплейс в России и СНГ. Наши клиенты делают больше 20 млн заказов ежедневно, 8 из 10 заказов доставляем на следующий день.
Мы ищем исполнителей для оказания услуг приемки, сортировки, упаковки и разгрузки товара — возможно, именно вас!

Вам предстоит:

    Упаковывать, сортировать, раскладывать на стеллажи или собирать товары – вы сами сможете выбрать вид операции

Почему стоит выбрать именно Wildberries:

    Стабильный доход с прозрачными условиями
    Выплачиваем вознаграждение каждые 3 дня, размер выплат зависит от количества и качества оказанных услуг, в среднем — до 10 000 ₽ за одно посещение склада.
    Ваше время - ценность
    Даем возможность планировать свой день, выбирая удобное время для оказания услуг.
    Бесплатный корпоративный транспорт
    Довезём вас до склада и обратно.
    Комфортные условия
    Питайтесь в столовой с комплексными обедами прямо на территории склада.

Вы нам подходите, если вы:

    Специальные навыки не требуются: мы подробно объясним и поможем быстро понять всю специфику задач.
    Внимательны, ответственны.
    Трудолюбивы и работоспособны.
TXT;

    public function handle(): int
    {
        $jsUrl      = (string) $this->option('js-url');
        $outFile    = (string) $this->option('outfile');
        $outPath    = storage_path('app/' . $outFile . today() . '.xlsx');

        try {
            $this->info("Загружаю JS: {$jsUrl}");
            $cities = $this->fetchCities($jsUrl); // [['label'=>'Абакан','value'=>0], ...]

            if (empty($cities)) {
                $this->warn('Города не найдены — файл не создан.');
                return self::SUCCESS;
            }

            $this->info('Формирую строки вакансий...');
            $rows = [];
            foreach ($cities as $c) {
                $rows[] = $this->makeVacancyRow(
                    (int)($c['value'] ?? null),
                    (string)($c['label'] ?? '')
                );
            }

            $this->info('Пишу Excel...');
            $this->writeXlsx($rows, $outPath);

            $this->info("Готово: {$outPath}");
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * Получает массив городов из переменной lL в JS.
     * Ожидается формат:
     *   lL=[{label:"Абакан",value:0}, ...]
     *
     * @return array<int, array{label:string,value:int}>
     */
    private function fetchCities(string $url): array
    {
        $resp = Http::timeout(20)->get($url);
        if (!$resp->ok()) {
            throw new \RuntimeException("HTTP error: {$resp->status()}");
        }

        $js = $resp->body();

        // 1) Находим начало присваивания lL=[...
        if (!preg_match('/\blL\s*=\s*\[/u', $js, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException('Не удалось найти начало lL=[ в JS-файле.');
        }

        // Позиция первого символа '[' после lL=
        $startBracketPos = $m[0][1] + strlen($m[0][0]) - 1; // указывает на '['

        // 2) Идём вперёд и балансируем скобки, корректно обрабатывая строки и экранирование
        $len   = strlen($js);
        $i     = $startBracketPos;
        $depth = 0;
        $inStr = false;   // текущий символ внутри строки?
        $strQ  = '';      // кавычка строки: ' или "
        while ($i < $len) {
            $ch = $js[$i];

            if ($inStr) {
                if ($ch === '\\') {
                    // пропускаем экранированный следующий символ
                    $i += 2;
                    continue;
                }
                if ($ch === $strQ) {
                    $inStr = false;
                }
            } else {
                if ($ch === '"' || $ch === "'") {
                    $inStr = true;
                    $strQ  = $ch;
                } elseif ($ch === '[') {
                    $depth++;
                } elseif ($ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        // Конец массива найден ровно здесь
                        $endBracketPos = $i;
                        $arrayJs = substr($js, $startBracketPos, $endBracketPos - $startBracketPos + 1);
                        // 3) Санитация к JSON

                        // Удаляем комментарии
                        $arrayJs = preg_replace('#//.*?$#m', '', $arrayJs);
                        $arrayJs = preg_replace('#/\*[\s\S]*?\*/#', '', $arrayJs);

                        // Убираем хвостовые запятые перед } или ]
                        $arrayJs = preg_replace('/,(\s*[}\]])/u', '$1', $arrayJs);

                        // Добавляем кавычки к ключам объектов: label: -> "label":
                        $json = preg_replace('/([{,]\s*)([A-Za-z_$][\w$]*)\s*:/u', '$1"$2":', $arrayJs);

                        // Нормализуем одинарные кавычки в значениях на двойные (если попадутся)
                        $json = preg_replace_callback(
                            '/:\s*\'((?:\\\\\'|[^\'])*?)\'/u',
                            static fn($m2) => ': "'.str_replace(['\\\''], ["'"], $m2[1]).'"',
                            $json
                        );

                        $data = json_decode($json, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            // сохраняем дамп для диагностики
                            $dumpPath = storage_path('app/wb_ll_raw_dump.json.txt');
                            @file_put_contents($dumpPath, $json);
                            throw new \RuntimeException('JSON decode error: ' . json_last_error_msg() . " (дамп: {$dumpPath})");
                        }
                        if (!is_array($data)) {
                            throw new \RuntimeException('Неверный формат данных после декодирования.');
                        }
                        return $data;
                    }
                }
            }
            $i++;
        }

        throw new \RuntimeException('Не удалось сбалансировать массив lL — конец ] не найден.');
    }

    /**
     * Формирует одну строку вакансии по городу.
     */
    private function makeVacancyRow(int $cityId, string $cityTitle): array
    {
        return [
            'city_id'       => $cityId,
            'city_title'    => $cityTitle,
            'vacancy_title' => self::VACANCY_TITLE,
            'salary_from'   => self::SALARY_FROM,
            'salary_to'     => self::SALARY_TO,
            'salary_text'   => $this->formatSalary(self::SALARY_FROM, self::SALARY_TO),
            'description'   => self::DESCRIPTION,
            'url' => 'https://career.wb.ru/storage'
        ];
    }

    private function formatSalary(int $from, int $to): string
    {
        // 110 000 до 200 000 ₽
        $fmt = fn(int $n) => number_format($n, 0, ',', ' ');
        return $fmt($from) . ' до ' . $fmt($to) . ' ₽';
    }

    /**
     * Записывает XLSX по схеме колонок.
     *
     * @param array<int, array<string, scalar|null>> $rows
     */
    private function writeXlsx(array $rows, string $outPath): void
    {
        $columnKeys = array_keys(self::COLUMN_SCHEMA);
        $headers    = array_values(self::COLUMN_SCHEMA);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Vacancies by City');

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
