<?php

namespace App\Console\Commands\WB;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class GenerateDriverCEVacanciesWB extends Command
{
    protected $signature = 'wb:generate-driver-ce-vacancies
        {--xml-outfile=wb_driver_ce_vacancies : Имя XML-файла в storage/app (без .xml)}';

    protected $description = 'Извлекает города из JS-ассета driver-ce и формирует XML-фид вакансий водителей Wildberries.';

    private const VACANCY_TITLE = 'Водитель категории CE (доставка между складами)';
    private const SALARY_FROM   = null;
    private const SALARY_TO     = 250000;
    private const XML_HOST      = 'job.wb.ru';

    private const DESCRIPTION = <<<TXT
            Приглашаем к сотрудничеству водителей категории «B» со стажем работы от 1 го года на автомобилях "Газель" и "Sollers Atlant".
            Мы предлагаем работу, на новых автомобилях компании марки Газель и Sollers Atlant, для осуществления доставки заказов из сортировочных центров (СЦ) до Пунктов Выдачи Заказов (ПВЗ).
            Совокупный доход в месяц от 105 000 до 200 000 руб.
            Работа сдельная с оплатой в конце смены.
            Компания предоставляет корпоративный транспорт до СЦ.
            Что нужно делать:
            Доставлять заказы с СЦ до ПВЗ (фиксированный район маршрута)
            Осуществлять возврат невостребованных заказов на СЦ
            Осуществлять прием и сдачу ТС в начале и конце смены
            Бережная эксплуатация ТС
            Использование специального мобильного приложения компании.
            Для сотрудничества с Wildberries Вам нужно иметь:
            Статус самозанятого  если не хотите в штат
            Наличие прав категории «B»
            Телефон на операционной системе Android
            Грамотная речь
            Стрессоустойчивость
            Знание устройства автомобиля
            Мы бы хотели, чтобы у Вас было:
            Образование средне-профессиональное и выше
            Опыт работы водителем категории B (типа Газель) – от 1 года
            Наличие профильного образования в автомобильной области приветствуется
            Опыт сотрудничества в сфере доставки интернет-заказов будет преимуществом
        TXT;

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
            $this->info('Ищу JS-ассет со списком городов...');
            [$chunkUrl, $arrayJson] = $this->locateChunkWithCityArray();

            $this->info("Обрабатываю ассет: {$chunkUrl}");
            $cities = $this->parseCityArray($arrayJson);

            if (empty($cities)) {
                $this->warn('Список городов пуст — файл не создан.');
                return self::SUCCESS;
            }

            $rows = [];
            foreach ($cities as $city) {
                $rows[] = $this->makeVacancyRow(
                    (int)($city['id'] ?? null),
                    (string)($city['name'] ?? '')
                );
                $this->line("Найден город: " . ($city['name'] ?? '—') . ' (ID: ' . ($city['id'] ?? '—') . ')');
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

    private function locateChunkWithCityArray(): array
    {
        $resp = Http::timeout(20)->get('https://job.wb.ru/driver-ce');
        if (!$resp->ok()) {
            throw new \RuntimeException('Не удалось загрузить страницу driver-ce.');
        }

        $html = $resp->body();

        if (!preg_match_all(
            '/(?:src|href)\s*=\s*["\']([^"\']*chunk-[A-Za-z0-9]+\.js)["\']/iu',
            $html,
            $matches
        )) {
            throw new \RuntimeException('Не найден ни один chunk-ассет на странице driver-ce.');
        }

        $seen = [];

        foreach ($matches[1] as $path) {
            $path = trim($path);
            if ($path === '' || isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            $url = str_starts_with($path, 'http')
                ? $path
                : 'https://job.wb.ru/' . ltrim($path, '/');

            $chunkResp = Http::timeout(20)->get($url);
            if (!$chunkResp->ok()) {
                continue;
            }

            $body = $chunkResp->body();
            if ($arrayJson = $this->extractCityArrayJson($body)) {
                return [$url, $arrayJson];
            }
        }

        throw new \RuntimeException('Не удалось найти chunk со списком городов.');
    }

    private function extractCityArrayJson(string $jsContent): ?string
    {
        $len = strlen($jsContent);
        $offset = 0;

        $pattern = '/(?:var|let|const)?\s*[A-Za-z_$][A-Za-z0-9_$]*\s*=\s*\[/iu';
        if (!preg_match_all($pattern, $jsContent, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        foreach ($matches[0] as $matchData) {
            $bracketPos = strpos($jsContent, '[', $matchData[1]);
            if ($bracketPos === false) {
                continue;
            }

            $candidate = $this->extractBalancedArray($jsContent, $bracketPos);
            if ($candidate === null) {
                continue;
            }

            $normalized = preg_replace('/([{,]\s*)([a-zA-Z_][a-zA-Z0-9_]*)\s*:/u', '$1"$2":', $candidate);
            $normalized = str_replace("'", '"', $normalized);
            $normalized = preg_replace_callback(
                '/\\\x([0-9A-Fa-f]{2})/',
                fn(array $matches) => '\\u00' . strtoupper($matches[1]),
                $normalized
            );

            if ($this->isValidCityArray($normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function isValidCityArray(string $json): bool
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (empty($decoded)) {
            return false;
        }

        foreach ($decoded as $item) {
            if (
                !is_array($item) ||
                !isset($item['id'], $item['name']) ||
                !preg_match('/^\d+$/', (string)$item['id'])
            ) {
                return false;
            }
        }

        return true;
    }

    private function extractBalancedArray(string $content, int $start): ?string
    {
        $len = strlen($content);
        $depth = 0;
        $inStr = false;
        $strChar = '';

        for ($i = $start; $i < $len; $i++) {
            $char = $content[$i];

            if ($inStr) {
                if ($char === '\\') {
                    $i++;
                    continue;
                }
                if ($char === $strChar) {
                    $inStr = false;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $inStr = true;
                $strChar = $char;
                continue;
            }

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($content, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }


    private function parseCityArray(string $jsonArray): array
    {
        $data = json_decode($jsonArray, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Список городов не удалось распарсить как JSON.');
        }

        return array_filter($data, static fn($item) => is_array($item) && isset($item['id'], $item['name']));
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
            'url' => 'https://job.wb.ru/driver-ce',
            'mobile_url' => 'https://job.wb.ru/driver-ce',
            'creation_date' => $this->formatNow(),
            'update_date' => $this->formatNow(),
            'job_name' => $row['vacancy_title'] ?? null,
            'description' => $description,
            'company_name' => 'Wildberries',
            'company_description' => 'Wildberries — крупнейший маркетплейс, объединяющий логистику по всей России.',
            'campaign' => 'Водитель WB #' . ($row['city_title'] ?? 'город'),
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
                'industry' => 'Транспорт и логистика',
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
