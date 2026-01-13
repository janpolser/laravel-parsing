<?php

namespace App\Console\Commands\WB;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GenerateStorageVacanciesWB extends Command
{
    protected $signature = 'wb:generate-storage-vacancies
        {--xml-outfile=wb_storage_vacancies : Имя XML-файла в storage/app (без .xml)}';

    protected $description = 'Извлекает список городов из JS и формирует XML-фид вакансий Wildberries.';

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

    private const XML_HOST = 'career.wb.ru';

    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();
        $this->xmlFormatter = $xmlFormatter;
    }

    public function handle(): int
    {
        $xmlOutFile = (string) $this->option('xml-outfile');
        $xmlPath    = storage_path('app/public/wb/' . $xmlOutFile . today()->toDateString() . '.xml');

        try {
            $this->info('Шаг 1: Ищем актуальный JS-файл...');
            $jsUrl = $this->discoverJavascriptUrl();
            $this->info("Найден актуальный скрипт: {$jsUrl}");

            $this->info('Шаг 2: Парсим список городов из JS...');
            $jsContent = $this->fetchJsContent($jsUrl);

            $pattern = '/\{label:"([^"]+)",value:(\d+)\}/u';
            if (!preg_match_all($pattern, $jsContent, $citiesMatches, PREG_SET_ORDER)) {
                $this->error('Список городов не найден в файле. Возможно, изменился формат данных.');
                return self::FAILURE;
            }

            $cities = [];
            $count = 0;
            foreach ($citiesMatches as $match) {
                $cityName = $match[1];
                $cityId = (int) $match[2];
                $cities[] = ['label' => $cityName, 'value' => $cityId];
                $this->line("Найдено: {$cityName} (ID: {$cityId})");
                $count++;
            }
            $this->info("Всего обновлено городов: {$count}");

            if (empty($cities)) {
                $this->warn('Города не найдены — файл не создан.');
                return self::SUCCESS;
            }

            $this->info('Формирую строки вакансий...');
            $rows = [];
            foreach ($cities as $cityData) {
                $rows[] = $this->makeVacancyRow(
                    (int)($cityData['value'] ?? null),
                    (string)($cityData['label'] ?? '')
                );
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

    private function discoverJavascriptUrl(): string
    {
        $resp = Http::timeout(20)->get('https://career.rwb.ru/storage');
        if (!$resp->ok()) {
            throw new \RuntimeException('Не удалось загрузить главную страницу: HTTP ' . $resp->status());
        }

        $html = $resp->body();
        if (!preg_match('/src="(\/assets\/index-[a-zA-Z0-9]+\.js)"/', $html, $matches)) {
            throw new \RuntimeException('Не удалось найти ссылку на JS-файл. Возможно, изменилась верстка.');
        }

        return 'https://career.rwb.ru' . $matches[1];
    }

    private function fetchJsContent(string $url): string
    {
        $resp = Http::timeout(20)->get($url);
        if (!$resp->ok()) {
            throw new \RuntimeException("Не удалось скачать JS: HTTP {$resp->status()}");
        }

        return $resp->body();
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

    private function buildXmlEntities(array $rows): array
    {
        $entities = array_values(array_filter(array_map(
            fn(array $row) => $this->mapRowToXmlEntity($row),
            $rows
        )));
        return $entities;
    }

    private function mapRowToXmlEntity(array $row): array
    {
        $description = $this->composeXmlDescription($row);
        if (!$description) {
            return [];
        }

        $entity = [
            'url' => 'https://career.wb.ru/storage',
            'mobile_url' => 'https://career.wb.ru/storage',
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
                'industry' => 'Складские услуги',
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
