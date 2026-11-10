<?php

namespace App\Console\Commands\Oez;

use App\Services\Oez\VacancyCatalog;
use App\Services\YandexFeedXmlFormat;
use App\Support\VacancyTextUrls;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CollectVacancies extends Command
{
    protected $signature = 'oez:collect-vacancies
        {--max-pages=0 : Максимум страниц для preview, 0 — все доступные}
        {--per-page=100 : Количество вакансий на странице HH API, максимум 100}
        {--search-period=1 : Период публикации в днях, как на career-oez.ru}
        {--outfile=oez_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии ОЭЗ Алабуга через публичный HH API и формирует YVL XML';

    public function handle(VacancyCatalog $catalog, YandexFeedXmlFormat $formatter): int
    {
        $maxPages = (int) $this->option('max-pages');
        $perPage = (int) $this->option('per-page');
        $searchPeriod = (int) $this->option('search-period');
        $outfile = (string) $this->option('outfile');
        if ($maxPages < 0 || $perPage < 1 || $perPage > 100 || $searchPeriod < 1
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }

        try {
            $rows = $catalog->collect($maxPages, $perPage, $searchPeriod);
            $preview = $maxPages > 0 || $perPage !== 100 || $searchPeriod !== 1 || $outfile !== 'oez_vacancies';
            $this->info('Вакансий HH: '.count($rows));
            $entities = array_map(fn (array $row): array => $this->mapVacancy($row), $rows);
            if ($entities === []) {
                throw new \RuntimeException('HH не вернул ни одной вакансии');
            }

            $dir = $preview ? 'app/oez-previews/' : 'app/public/oez/';
            $path = storage_path($dir.$outfile.today()->toDateString().'.xml');
            $formatter->createXmlFeed($entities, 'career-oez.ru', $path);
            $this->info('XML сформирован: '.$path);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор ОЭЗ не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function mapVacancy(array $row): array
    {
        $id = (string) $row['id'];
        $snippet = is_array($row['snippet'] ?? null) ? $row['snippet'] : [];
        $description = trim(implode("\n\n", array_filter([
            $snippet['responsibility'] ?? null,
            $snippet['requirement'] ?? null,
        ], static fn ($value) => is_string($value) && trim($value) !== '')));
        if ($description === '') {
            $description = 'Актуальная вакансия ОЭЗ «Алабуга». Подробности и отклик доступны на странице вакансии.';
        }
        $descriptionFields = VacancyTextUrls::extractFromVacancy(['description' => $description]);
        $salary = is_array($row['salary'] ?? null) ? $row['salary'] : [];
        $from = is_numeric($salary['from'] ?? null) && $salary['from'] > 0 ? $salary['from'] : null;
        $to = is_numeric($salary['to'] ?? null) && $salary['to'] > 0 ? $salary['to'] : null;
        $salaryText = trim(($from !== null ? 'от '.$from : '').($to !== null ? ' до '.$to : ''));
        $address = is_array($row['address'] ?? null) ? $row['address'] : [];
        $location = $address['raw'] ?? $address['city'] ?? ($row['area']['name'] ?? '');
        $roles = is_array($row['professional_roles'] ?? null) ? $row['professional_roles'] : [];
        $role = $roles[0]['name'] ?? null;

        return array_filter([
            'url' => $row['alternate_url'] ?? 'https://hh.ru/vacancy/'.$id,
            'mobile_url' => $row['alternate_url'] ?? null,
            'creation_date' => $this->date($row['published_at'] ?? $row['created_at'] ?? null),
            'salary' => $salaryText ?: null,
            'currency' => $salaryText && is_string($salary['currency'] ?? null) ? $salary['currency'] : null,
            'category' => ['specialization' => $role],
            'job_name' => $row['name'] ?? null,
            'employment' => $row['employment_form']['name'] ?? null,
            'schedule' => $row['work_schedule_by_days'][0]['name'] ?? null,
            'description' => $descriptionFields['description'],
            'text_urls' => $descriptionFields['text_urls'] ?? [],
            'requirement' => ['experience' => $row['experience']['name'] ?? null],
            'addresses' => [[
                'location' => $location,
                'lat' => $address['lat'] ?? null,
                'lng' => $address['lng'] ?? null,
            ]],
            'company_name' => 'ОЭЗ «Алабуга»',
            'company_site' => 'https://career-oez.ru/',
            'hr_agency' => false,
            'campaign' => 'ОЭЗ Алабуга #'.$id,
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function date(mixed $value): string
    {
        try {
            $date = is_string($value) && $value !== '' ? Carbon::parse($value, 'Europe/Moscow') : Carbon::now('Europe/Moscow');
        } catch (\Throwable) {
            $date = Carbon::now('Europe/Moscow');
        }

        return $date->setTimezone('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3';
    }
}
