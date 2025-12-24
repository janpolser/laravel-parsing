<?php

namespace App\Console\Commands\Magnit;

use App\Services\YandexFeedXmlFormat;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CollectVacancy extends Command
{
    protected $signature = 'app:collect-vacancy-magnit';

    protected $description = 'Парсинг вакансий Магнит с устойчивостью и потоковой записью';

    public function handle(YandexFeedXmlFormat $xml)
    {
        $this->info('Начало сбора вакансий Магнит...');

        // Запрос локаций
        $this->info('Получение списка локаций...');
        $locationResponse = $this->safeRequest('https://rabota.magnit.ru/api/v1/locality?page=1&per_page=20000');
        $locations = array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'slug' => $i['slug']], $locationResponse['results'] ?? []);

        if (empty($locations)) {
            $this->error('Не удалось получить список локаций');
            return 1;
        }

        $this->info('Найдено локаций: ' . count($locations));

        date_default_timezone_set('Europe/Moscow');
        $date = new DateTime;

        $editedVacancies = [];
        $totalLocations = count($locations);
        $processedLocations = 0;

        // Прогресс-бар для обработки локаций
        $locationsProgressBar = $this->output->createProgressBar($totalLocations);
        $locationsProgressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %message%');
        $locationsProgressBar->setMessage('Обработка локаций...');
        $locationsProgressBar->start();

        foreach ($locations as $item) {
            $page = 1;
            $totalPages = 1; // неизвестно заранее
            $vacanciesInLocation = 0;

            // Прогресс-бар для страниц внутри локации
            $pagesProgressBar = null;

            do {
                $url = 'https://rabota.magnit.ru/api/v1/vacancy?locality_id[]=' . $item['id'] .
                    '&overview=list&per_page=500&page=' . $page;

                $vacancyResponse = $this->safeRequest($url);

                if (!$vacancyResponse || !isset($vacancyResponse['results']) || !is_array($vacancyResponse['results'])) {
                    break;
                }

                // Создаем прогресс-бар для страниц при первой итерации
                if ($page === 1) {
                    $totalPages = $vacancyResponse['pagination']['total_pages'] ?? 1;
                    $pagesProgressBar = $this->output->createProgressBar($totalPages);
                    $pagesProgressBar->setFormat('  Страница %current%/%max% [%bar%] %percent:3s%%');
                    $pagesProgressBar->start();
                }

                foreach ($vacancyResponse['results'] as $vacancyIndex => $vacancy) {
                    if ($vacancy['active']) {
                        $editedVacancy['url'] = 'https://rabota.magnit.ru/' . $item['slug'] . '/vacancy/' . $vacancy['id'];
                        $editedVacancy['mobile_url'] = $editedVacancy['url'];
                        $editedVacancy['creation_date'] = $date->format('Y-m-d H:i:s') . ' GMT+3';
                        $editedVacancy['salary'] = $vacancy['salary_human'];

                        $editedVacancy['currency'] = 'RUB';
                        $editedVacancy['category']['industry'] = $vacancy['properties']['professional_areas'][0]['name'] ?? '';
                        $editedVacancy['job_name'] = $vacancy['name'];
                        $editedVacancy['employment'] = $vacancy['properties']['schedules'][0] ?? '';
                        $editedVacancy['schedule'] = $vacancy['schedule'][0]['name'] ?? '';

                        $description = 'Обязанности:';
                        foreach ($vacancy['responsibilities'] as $responsibility) {
                            $description = $description . '<br>' . $responsibility;
                        }
                        $editedVacancy['description'] = $description;

                        $editedVacancy['addresses']['address']['location'] = $vacancy['address'] ?? '';
                        $editedVacancy['company_name'] = 'Магнит';
                        $editedVacancy['hr_agency'] = 'false';

                        $editedVacancies[] = $editedVacancy;
                        $vacanciesInLocation++;
                    }
                }

                // Обновляем прогресс-бар страниц
                if ($pagesProgressBar) {
                    $pagesProgressBar->advance();
                }

                $hasNextPage = false;
                if (isset($vacancyResponse['next']) && !empty($vacancyResponse['next'])) {
                    $hasNextPage = true;
                } elseif (isset($vacancyResponse['pagination']) &&
                    $page < $vacancyResponse['pagination']['total_pages']) {
                    $hasNextPage = true;
                } elseif (count($vacancyResponse['results']) == 500) {
                    $hasNextPage = true;
                }

                $page++;

            } while ($hasNextPage);

            // Завершаем прогресс-бар страниц для текущей локации
            if ($pagesProgressBar) {
                $pagesProgressBar->finish();
                $pagesProgressBar->clear();
                $this->line("  Найдено вакансий в локации '{$item['name']}': {$vacanciesInLocation}");
            }

            // Обновляем основной прогресс-бар
            $processedLocations++;
            $locationsProgressBar->setMessage("Обработано вакансий: " . count($editedVacancies));
            $locationsProgressBar->advance();
        }

        $locationsProgressBar->finish();
        $locationsProgressBar->clear();

        $this->info(PHP_EOL . 'Обработка локаций завершена.');
        $this->info('Всего собрано вакансий: ' . count($editedVacancies));

        // Создание XML
        $this->info('Создание XML файла...');
        $xml->createXmlFeed($editedVacancies, 'https://rabota.magnit.ru', 'storage/app/public/magnit/MagnitVacancies' . today() . '.xml');

        $this->info('Готово! XML файл сохранен: storage/app/public/magnit/vacancies.xml');

        return 0;
    }

    private function safeRequest(string $url, int $attempts = 5, int $delaySeconds = 3)
    {
        for ($i = 1; $i <= $attempts; $i++) {
            try {
                $response = Http::timeout(30)->get($url);

                if ($response->successful()) {
                    $json = $response->json();
                    if (is_array($json) || is_object($json)) {
                        return $json;
                    }
                    // Неправильный JSON
                    $this->warn("safeRequest: неверный JSON на попытке {$i} для {$url}");
                    Log::warning('GetVacancyByCurl: invalid json', ['url' => $url, 'body' => $response->body()]);
                } else {
                    $this->warn("Ошибка HTTP ({$response->status()}) на попытке {$i}: {$url}");
                    Log::warning('GetVacancyByCurl: http status', ['status' => $response->status(), 'url' => $url]);
                }
            } catch (\Throwable $e) {
                $this->warn("Ошибка на попытке {$i}: {$e->getMessage()}");
                Log::warning('GetVacancyByCurl: exception during request', ['attempt' => $i, 'url' => $url, 'exception' => $e->getMessage()]);
            }

            // экспоненциальная пауза (чтобы снизить нагрузку при проблемах)
            sleep($delaySeconds * $i);
        }

        $this->error("Не удалось получить данные после {$attempts} попыток: {$url}");
        Log::error('GetVacancyByCurl: request failed after attempts', ['url' => $url]);

        return null;
    }
}
