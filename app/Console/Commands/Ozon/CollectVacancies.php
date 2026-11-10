<?php

namespace App\Console\Commands\Ozon;

use App\Services\Ozon\VacancyCatalog;
use App\Services\YandexFeedXmlFormat;
use App\Support\VacancyTextUrls;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class CollectVacancies extends Command
{
    protected $signature = 'ozon:collect-vacancies
        {--city=Москва : Город, передаваемый в фильтр Ozon}
        {--max-pages=0 : Максимум страниц для preview, 0 — все}
        {--detail-limit=0 : Максимум карточек для preview, 0 — все}
        {--sleep-ms=100 : Задержка между запросами карточек}
        {--outfile=ozon_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии Ozon через API и формирует YVL XML';

    public function handle(VacancyCatalog $catalog, YandexFeedXmlFormat $formatter): int
    {
        $city = trim((string) $this->option('city'));
        $maxPages = filter_var($this->option('max-pages'), FILTER_VALIDATE_INT);
        $detailLimit = filter_var($this->option('detail-limit'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');
        if ($city === '' || $maxPages === false || $maxPages < 0 || $detailLimit === false || $detailLimit < 0
            || $sleepMs === false || $sleepMs < 0 || $sleepMs > 10000
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры города, лимита, задержки или имени файла');

            return self::INVALID;
        }

        $preview = $maxPages > 0 || $detailLimit > 0 || $city !== 'Москва' || $outfile !== 'ozon_vacancies';

        try {
            $rows = $catalog->collect($city, $maxPages);
            $types = array_count_values(array_column($rows, 'vacancyType'));
            $this->info('Всего записей API: '.count($rows).'; типы: '.json_encode($types, JSON_UNESCAPED_UNICODE));

            $jobs = [];
            $virtual = 0;
            $hhExcluded = 0;
            foreach ($rows as $row) {
                if ($row['vacancyType'] === 'virtual_vacancy') {
                    $virtual++;

                    continue;
                }
                if (!in_array($row['vacancyType'], ['external_vacancy', 'internal_vacancy'], true)) {
                    throw new \RuntimeException('Неизвестный тип вакансии: '.$row['vacancyType']);
                }

                $hhId = $row['hhId'] ?? null;
                if ((!is_int($hhId) && !is_string($hhId)) || !ctype_digit((string) $hhId)) {
                    throw new \RuntimeException('Неизвестен hhId вакансии '.$row['internalUuid']);
                }
                if ((int) $hhId > 0) {
                    $hhExcluded++;

                    continue;
                }
                if ($row['vacancyType'] !== 'internal_vacancy') {
                    throw new \RuntimeException('Не удалось определить источник внешней вакансии '.$row['internalUuid']);
                }
                $jobs[] = $row;
            }
            $this->info("Исключено вакансий с hhId: {$hhExcluded}");
            if ($virtual > 0) {
                $this->warn("Пропущено виртуальных ссылок без подтверждённого города и карточки: {$virtual}");
            }
            if ($detailLimit > 0) {
                $jobs = array_slice($jobs, 0, $detailLimit);
            }

            $entities = [];
            $missing = 0;
            foreach ($jobs as $index => $row) {
                if ($index > 0 && $sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
                $detail = $this->fetchDetail($row);
                if ($detail === null) {
                    $missing++;
                    $this->warn('Карточка удалена или скрыта: '.$row['internalUuid']);

                    continue;
                }
                $entities[] = $this->mapVacancy($row, $detail);
                if (($index + 1) % 50 === 0) {
                    $this->line('Обработано карточек: '.($index + 1).'/'.count($jobs));
                }
            }
            if ($entities === []) {
                throw new \RuntimeException('Не найдено ни одной полной карточки');
            }

            $dir = $preview ? 'app/ozon-previews/' : 'app/public/ozon/';
            $path = storage_path($dir.$outfile.today()->toDateString().'.xml');
            $formatter->createXmlFeed($entities, 'career.ozon.ru', $path);
            $this->info('Полных карточек: '.count($entities)."; удалённых: {$missing}; hh исключено: {$hhExcluded}; виртуальных исключено: {$virtual}");
            $this->info('XML сформирован: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Ozon не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function fetchDetail(array $row): ?array
    {
        $id = $row['internalUuid'];
        $url = VacancyCatalog::URL.'/'.$id;

        $response = Http::acceptJson()->timeout(30)->connectTimeout(10)->retry(2, 500, throw: false)->get($url);
        if ($response->status() === 404) {
            return null;
        }
        if ($response->status() === 400 && $response->json('message') === 'Вакансия скрыта') {
            return null;
        }
        if (!$response->ok()) {
            throw new \RuntimeException("HTTP {$response->status()} при загрузке карточки {$id}");
        }
        $data = $response->json();
        $description = is_array($data) ? ($data['descr'] ?? $data['description'] ?? null) : null;
        if (!is_array($data) || !is_string($data['name'] ?? null) || trim($data['name']) === ''
            || !is_string($description) || $this->text($description) === '') {
            throw new \RuntimeException("Некорректная или пустая карточка {$id}");
        }

        return $data;
    }

    private function mapVacancy(array $row, array $detail): array
    {
        $id = $row['internalUuid'];
        $department = (string) ($row['department'] ?? '');
        if (str_contains(mb_strtolower($department), 'банк')) {
            $url = 'https://career.ozon.ru/fintech/vacancy?id='.$id.'&isInternal=true';
        } elseif (str_contains(mb_strtolower($department), 'технологии') || str_contains(mb_strtolower($department), 'tech')) {
            $url = 'https://ozon.tech/vacancies/'.$id.'/';
        } else {
            $url = 'https://career.ozon.ru/vacancy/'.$this->slug($row['title']).'-'.$id;
        }

        $salary = $detail['salary'] ?? null;
        $from = is_array($salary) && is_numeric($salary['from'] ?? null) && $salary['from'] > 0 ? $salary['from'] : null;
        $to = is_array($salary) && is_numeric($salary['to'] ?? null) && $salary['to'] > 0 ? $salary['to'] : null;
        $currency = is_array($salary) && is_string($salary['currency'] ?? null) ? $salary['currency'] : '';
        $salaryText = trim(($from ? 'от '.$from : '').($to ? ' до '.$to : ''));
        $description = $this->text($detail['descr'] ?? $detail['description']);
        $descriptionFields = VacancyTextUrls::extractFromVacancy(['description' => $description]);
        if (empty($descriptionFields['description'])) {
            throw new \RuntimeException("Нет описания после обработки ссылок: {$id}");
        }
        $location = $detail['city'] ?? $row['city'] ?? '';
        if (!is_string($location) || trim($location) === '') {
            throw new \RuntimeException("Не указан город вакансии {$id}");
        }
        $formats = $row['workFormat'] ?? [];
        $schedule = is_array($formats) ? implode(', ', array_filter($formats, 'is_string')) : '';
        $date = $this->date($detail['publishedAt'] ?? null);

        return array_filter([
            'url' => $url,
            'mobile_url' => $url,
            'creation_date' => $date,
            'salary' => $salaryText ?: null,
            'currency' => $salaryText && $currency !== '' ? ($currency === 'RUR' ? 'RUR' : $currency) : null,
            'job_name' => $detail['name'],
            'employment' => $detail['employment'] ?? $row['employment'] ?? null,
            'schedule' => $schedule ?: null,
            'description' => $descriptionFields['description'],
            'text_urls' => $descriptionFields['text_urls'] ?? [],
            'category' => ['industry' => $department],
            'requirement' => ['experience' => $detail['exp'] ?? $detail['experience'] ?? $row['experience'] ?? null],
            'addresses' => [['location' => $this->text($location)]],
            'company_name' => 'Ozon',
            'company_site' => 'https://www.ozon.ru/',
            'hr_agency' => false,
            'campaign' => 'Ozon #'.$id,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function date(mixed $value): string
    {
        try {
            $date = is_string($value) && $value !== '' ? Carbon::parse($value, 'Europe/Moscow')->setTimezone('Europe/Moscow') : Carbon::now('Europe/Moscow');
        } catch (\Throwable) {
            $date = Carbon::now('Europe/Moscow');
        }

        return $date->format('Y-m-d H:i:s').' GMT+3';
    }

    private function text(string $value): string
    {
        $value = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|div|li|ul|ol|h[1-6])\s*>/iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;

        return trim($value);
    }

    private function slug(string $value): string
    {
        return Str::slug(strtr(mb_strtolower($value), ['х' => 'kh', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'shch']));
    }
}
