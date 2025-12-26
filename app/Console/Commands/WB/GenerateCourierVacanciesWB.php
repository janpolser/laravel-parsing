<?php

namespace App\Console\Commands\WB;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GenerateCourierVacanciesWB extends Command
{
    protected $signature = 'wb:generate-courier-vacancies
        {--xml-outfile=wb_courier_vacancies : Имя XML-файла в storage/app (без .xml)}';

    protected $description = 'Извлекает список городов из JS и формирует XML-фид вакансий курьеров Wildberries.';

    private const VACANCY_TITLE = 'Курьер';
    private const SALARY_FROM   = null;
    private const SALARY_TO     = 250000;

    private const DESCRIPTION = <<<TXT
    Вам предстоит

        доставлять заказы клиентам из пунктов выдачи заказов. Расстояние доставок — не больше километра.

    Почему стоит выбрать именно Wildberries

        Стабильный доход до 8 000 рублей в день Выплачиваем вознаграждение каждую неделю на карту.
        Комфортные условия Предоставляем свободный график с короткими маршрутами, в среднем — 400 м до клиента. Доставлять можно как пешком, так и любым удобным для вас способом.
        Удобное приложение Разработали удобное приложение для выбора заказа: простой контроль за вознаграждением, встроенный навигатор и карта.

    Вы нам подходите, если:

        выполняете задачи ответственно и аккуратно,
        общаетесь вежливо и доброжелательно.
    TXT;

    private const XML_HOST = 'wbk.wb.ru';

    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();
        $this->xmlFormatter = $xmlFormatter;
    }

    public function handle(): int
    {
        $xmlOutFile = (string) $this->option('xml-outfile');
        $xmlPath    = storage_path('app/' . $xmlOutFile . today()->toDateString() . '.xml');

        try {
            $this->info('Запрашиваю список городов из API...');
            $cities = $this->fetchCitiesFromApi();

            if (empty($cities)) {
                $this->warn('Города не найдены — файл не создан.');
                return self::SUCCESS;
            }

            $this->info('Формирую строки вакансий...');
            $rows = [];
            foreach ($cities as $cityData) {
                $cityId = isset($cityData['id']) ? (int)$cityData['id'] : null;
                $cityName = isset($cityData['city']) ? (string)$cityData['city'] : '';
                if ($cityId === null || $cityName === '') {
                    continue;
                }
                $rows[] = $this->makeVacancyRow($cityId, $cityName);
                $this->line("Найдено: {$cityName} (ID: {$cityId})");
            }
            $this->info('Всего обновлено городов: ' . count($rows));

            if (empty($rows)) {
                $this->warn('После нормализации данных строк нет.');
                return self::SUCCESS;
            }

            $entities = $this->buildXmlEntities($rows);
            if (!empty($entities)) {
                $this->xmlFormatter->createXmlFeed($entities, self::XML_HOST, $xmlPath);
                $this->info("XML сформирован: {$xmlPath}");
                $this->info('Готово.');
            } else {
                $this->warn('Нет сущностей для XML — файл не создан.');
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Ошибка: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private const CITY_BATCH_LIMIT = 500;

    private function fetchCitiesFromApi(): array
    {
        $url = 'https://wbk.wb.ru/community-utils/api/feedback/city';
        $cities = [];
        $offset = 0;

        do {
            $this->info("Запрашиваю {$offset}..+" . self::CITY_BATCH_LIMIT . ' городов...');
            $payload = [
                'search' => '',
                'limit' => self::CITY_BATCH_LIMIT,
                'offset' => $offset,
            ];

            $resp = Http::timeout(25)
                ->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                    'User-Agent' => 'curl/8.0',
                ])
                ->asJson()
                ->post($url, $payload);

            if (!$resp->ok()) {
                throw new \RuntimeException('Не удалось получить список городов: HTTP ' . $resp->status());
            }

            $batch = $resp->json('cities', []);
            if (!is_array($batch) || empty($batch)) {
                break;
            }

            $cities = array_merge($cities, $batch);
            $offset += self::CITY_BATCH_LIMIT;

        } while (true);

        return $cities;
    }

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
            'url' => 'https://wbk.wb.ru/'
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

    private function buildXmlEntities(array $rows): array
    {
        return array_values(array_filter(array_map(
            fn(array $row) => $this->mapRowToXmlEntity($row),
            $rows
        )));
    }

    private function mapRowToXmlEntity(array $row): array
    {
        $description = $this->composeXmlDescription($row);
        if (!$description) {
            return [];
        }

        $entity = [
            'url' => 'https://wbk.wb.ru/',
            'mobile_url' => 'https://wbk.wb.ru/',
            'creation_date' => $this->formatNow(),
            'update_date' => $this->formatNow(),
            'job_name' => $row['vacancy_title'] ?? null,
            'description' => $description,
            'company_name' => 'Wildberries',
            'company_description' => 'Wildberries — крупнейший маркетплейс в России и СНГ.',
            'campaign' => 'Wildberries — ' . ($row['city_title'] ?? 'город'),
        ];

        if (!empty($row['salary_text'])) {
            $entity['salary'] = $row['salary_text'];
            $entity['currency'] = 'RUR';
        }

        $cityName = trim((string)($row['city_title'] ?? ''));
        if ($cityName !== '') {
            $entity['addresses'] = [[
                'location' => $this->escapeForXml($cityName),
                'metro' => null,
                'lng' => null,
                'lat' => null,
            ]];
            $entity['category'] = [
                'industry' => 'Логистика и доставка',
                'specialization' => $cityName,
            ];
        }

        return array_filter($entity, function ($value) {
            if ($value === null || $value === '') {
                return false;
            }
            if (is_array($value) && empty($value)) {
                return false;
            }
            return true;
        });
    }

    private function composeXmlDescription(array $row): ?string
    {
        $parts = [];
        if (!empty($row['description'])) {
            $parts[] = $row['description'];
        }
        if (!empty($row['city_title'])) {
            $parts[] = 'Город: ' . $row['city_title'];
        }

        if (empty($parts)) {
            return null;
        }

        return $this->escapeForXml(implode("\n", $parts));
    }

    private function formatNow(): string
    {
        $now = Carbon::now('Europe/Moscow');
        return $now->format('Y-m-d H:i:s') . ' GMT' . $this->formatOffset($now);
    }

    private function formatOffset(Carbon $date): string
    {
        $offsetMinutes = $date->offsetMinutes;
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $absMinutes = abs($offsetMinutes);
        $hours = intdiv($absMinutes, 60);
        $minutes = $absMinutes % 60;

        $result = $sign . $hours;
        if ($minutes) {
            $result .= ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT);
        }

        return $result;
    }

    private function escapeForXml(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $text = preg_replace('/\s+/', ' ', $text);
        return str_replace(
            ['"', '&', '>', '<', '\''],
            ['&quot;', '&amp;', '&gt;', '&lt;', '&apos;'],
            trim($text)
        );
    }
}
