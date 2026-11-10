<?php

namespace App\Console\Commands\Lenta;

use App\Services\Lenta\VacancyCatalog;
use App\Services\YandexFeedXmlFormat;
use App\Support\VacancyTextUrls;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

class CollectVacancies extends Command
{
    protected $signature = 'lenta:collect-vacancies
        {--max-cities=0 : Максимум городов для preview, 0 — все города}
        {--sleep-ms=100 : Задержка между городами}
        {--outfile=lenta_vacancies : Базовое имя XML-файла}';

    protected $description = 'Собирает вакансии Lenta через публичный API и формирует YVL XML';

    public function handle(VacancyCatalog $catalog, YandexFeedXmlFormat $formatter): int
    {
        $maxCities = filter_var($this->option('max-cities'), FILTER_VALIDATE_INT);
        $sleepMs = filter_var($this->option('sleep-ms'), FILTER_VALIDATE_INT);
        $outfile = (string) $this->option('outfile');
        if ($maxCities === false || $maxCities < 0 || $sleepMs === false || $sleepMs < 0 || $sleepMs > 10000
            || !preg_match('/^[a-zA-Z0-9_-]+$/', $outfile)) {
            $this->error('Некорректные параметры запуска');

            return self::INVALID;
        }

        $preview = $maxCities > 0 || $outfile !== 'lenta_vacancies';
        try {
            $catalogData = $catalog->collect($maxCities, $sleepMs);
            $items = $catalogData['items'];
            $this->info('Города: '.count($catalogData['cities']).'; вакансий API: '.count($items)
                .'; скрытых: '.$catalogData['hidden'].'; повторов: '.$catalogData['duplicates']);
            $entities = array_map(fn (array $item) => $this->mapVacancy($item), $items);
            if ($entities === []) {
                throw new \RuntimeException('Lenta: не найдено ни одной вакансии');
            }

            $dir = $preview ? 'app/lenta-previews/' : 'app/public/lenta/';
            $path = storage_path($dir.$outfile.today()->toDateString().'.xml');
            File::ensureDirectoryExists(dirname($path));
            $formatter->createXmlFeed($entities, 'career.lenta.com', $path);
            $this->info('XML сформирован: '.$path.'; вакансий: '.count($entities));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Сбор Lenta не завершён; предыдущий фид сохранён: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function mapVacancy(array $item): array
    {
        $description = [];
        foreach (['responsibilities' => 'Обязанности', 'requirements' => 'Требования', 'conditions' => 'Условия'] as $field => $label) {
            $text = $this->text($item[$field] ?? '');
            if ($text !== '') {
                $description[] = $label.":\n".$text;
            }
        }
        $description = trim(implode("\n\n", $description));
        if ($description === '') {
            throw new \RuntimeException('Lenta: пустое описание вакансии '.$item['vacancyId']);
        }
        $salaryFrom = is_numeric($item['salaryFrom'] ?? null) && (float) $item['salaryFrom'] > 0 ? $item['salaryFrom'] : null;
        $salaryTo = is_numeric($item['salaryTo'] ?? null) && (float) $item['salaryTo'] > 0 ? $item['salaryTo'] : null;
        $salary = trim(($salaryFrom !== null ? 'от '.$salaryFrom : '').($salaryTo !== null ? ' до '.$salaryTo : ''));
        $location = trim((string) ($item['cityTitle'] ?? '')).((string) ($item['addressTitle'] ?? '') !== '' ? ', '.trim((string) $item['addressTitle']) : '');
        $url = 'https://career.lenta.com/jobapply/'.$item['vacancyId'];
        $descriptionFields = VacancyTextUrls::extractFromVacancy(['description' => $description]);

        return array_filter([
            'url' => $url, 'mobile_url' => $url,
            'creation_date' => Carbon::now('Europe/Moscow')->format('Y-m-d H:i:s').' GMT+3',
            'salary' => $salary ?: null, 'currency' => $salary ? 'RUR' : null,
            'job_name' => trim($item['title']), 'description' => $descriptionFields['description'],
            'text_urls' => $descriptionFields['text_urls'] ?? [],
            'category' => ['industry' => $item['businessDirectionTitle'] ?? null],
            'addresses' => [['location' => $location]], 'company_name' => 'Лента',
            'company_site' => 'https://career.lenta.com', 'hr_agency' => false,
            'campaign' => 'Лента',
        ], static fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function text(mixed $value): string
    {
        $value = is_string($value) ? $value : '';
        $value = preg_replace('/<\s*br\s*\/?>|<\/\s*(p|div|li|ul|ol|h[1-6])\s*>/iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $value);
        $value = preg_replace('/[^\S\n]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/ *\n */u', "\n", $value) ?? $value;

        return trim(preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value);
    }
}
