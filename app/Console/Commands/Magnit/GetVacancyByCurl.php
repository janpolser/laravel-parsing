<?php

namespace App\Console\Commands\Magnit;

use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GetVacancyByCurl extends Command
{
    protected $signature = 'app:get-vacancy-by-curl';

    protected $description = 'Парсинг вакансий Магнит с устойчивостью и потоковой записью';

    public function handle()
    {
        $dir = storage_path('app');
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                $this->error("Не удалось создать папку: {$dir}");
                Log::error("GetVacancyByCurl: cannot create directory {$dir}");

                return 1;
            }
        }

        $filename = $dir . '/magnit_vacancies_' . date('Y-m-d_H-i-s') . '.xlsx';
        $writer = null;
        $count = 0;
        $opened = false;

        // Регистрируем shutdown-функцию чтобы попытаться закрыть writer если скрипт упал
        register_shutdown_function(function () use (&$writer, &$opened) {
            if ($opened && $writer !== null) {
                try {
                    $writer->close();
                } catch (\Throwable $e) {
                    // ничего не делаем — попытка закрыть
                }
            }
        });

        try {
            // Создаём writer
            $writer = WriterEntityFactory::createXLSXWriter();

            try {
                $writer->openToFile($filename);
                $opened = true;
            } catch (\Throwable $e) {
                $this->error("Spout: не удалось открыть файл для записи: {$e->getMessage()}");
                Log::error('GetVacancyByCurl: Spout openToFile error: ' . $e->getMessage());

                return 1;
            }

            // Заголовки
            $headers = [
                'ID вакансии', 'URL', 'Название вакансии', 'Полное название', 'Обязанности',
                'Формат магазина', 'Зарплата от', 'Зарплата до', 'Отдел', 'Адрес',
                'Дата создания', 'График работы', 'Формат работы', 'Стажировка',
                'Широта', 'Долгота', 'Видимая зарплата', 'Теги', 'Навыки',
                'Образование', 'Город', 'ID города',
            ];

            $headerRow = WriterEntityFactory::createRowFromArray($headers);
            $writer->addRow($headerRow);

            // Получаем города
            $locationResponse = $this->safeRequest('https://rabota.magnit.ru/api/v1/locality?page=1&per_page=20000');

            if (!$locationResponse || !isset($locationResponse['results'])) {
                $this->warn('Не удалось получить список локаций или он пустой. Записан только заголовок.');
                Log::warning('GetVacancyByCurl: locations response empty or null', ['response' => $locationResponse]);
            } else {
                $locations = array_map(fn($i) => ['id' => $i['id'], 'name' => $i['name'], 'slug' => $i['slug']], $locationResponse['results'] ?? []);
                $totalLocations = count($locations);

                $this->info("Найдено городов для обработки: {$totalLocations}");

                // Прогресс-бар для городов
                $progressBar = $this->output->createProgressBar($totalLocations);
                $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s% | Город: %message%');
                $progressBar->setMessage('Старт...');
                $progressBar->start();

                foreach ($locations as $item) {
                    $progressBar->setMessage($item['name']);

                    $vacancyResponse = $this->safeRequest(
                        'https://rabota.magnit.ru/api/v1/vacancy?locality_id[]=' . $item['id'] . '&overview=list&per_page=2000'
                    );

                    if (!$vacancyResponse) {
                        $this->warn("\nГород {$item['name']} ({$item['id']}) — не удалось получить вакансии, продолжаем дальше.");
                        Log::warning('GetVacancyByCurl: vacancy request failed', ['city_id' => $item['id']]);

                        $progressBar->advance();

                        // продолжаем — уже записанные строки останутся в файле
                        continue;
                    }

                    if (isset($vacancyResponse['results']) && is_array($vacancyResponse['results'])) {
                        $cityVacanciesCount = count($vacancyResponse['results']);

                        foreach ($vacancyResponse['results'] as $vacancyIndex => $vacancy) {
                            $row = [
                                $vacancy['id'] ?? '',
                                'https://rabota.magnit.ru/' . $item['slug'] . '/vacancy/' . $vacancy['id'],
                                $vacancy['name'] ?? '',
                                $vacancy['full_name'] ?? '',
                                implode('; ', $vacancy['responsibilities'] ?? []),
                                $vacancy['company_division_format']['name'] ?? '',
                                $vacancy['salary_from'] ?? '',
                                $vacancy['salary_to'] ?? '',
                                $vacancy['department'] ?? '',
                                $vacancy['address'] ?? '',
                                $vacancy['created_at'] ?? '',
                                $this->extractSchedule($vacancy['schedule'] ?? []),
                                $vacancy['work_format'] ?? '',
                                !empty($vacancy['intership']) ? 'Да' : 'Нет',
                                $vacancy['latitude'] ?? '',
                                $vacancy['longitude'] ?? '',
                                $vacancy['salary_human'] ?? '',
                                $this->extractTags($vacancy['tags'] ?? []),
                                $this->extractSkills($vacancy['properties']['key_skills'] ?? []),
                                $this->extractEducations($vacancy['properties']['educations'] ?? []),
                                $item['name'],
                                $item['id'],
                            ];

                            try {
                                $writer->addRow(WriterEntityFactory::createRowFromArray($row));
                                $count++;
                            } catch (\Throwable $e) {
                                // При ошибке записи — логируем и пробуем продолжить
                                $this->warn("\nОшибка при добавлении строки: " . $e->getMessage());
                                Log::error('GetVacancyByCurl: writer addRow error', ['message' => $e->getMessage(), 'row' => $row]);
                            }
                        }

                        // Выводим информацию о количестве вакансий в текущем городе
                        if ($cityVacanciesCount > 0) {
                            $progressBar->setMessage("{$item['name']} ({$cityVacanciesCount} вакансий)");
                        }
                    } else {
                        $this->warn("\nНет результатов вакансий для города {$item['name']} ({$item['id']}).");
                        Log::info('GetVacancyByCurl: no results for city', ['city' => $item]);
                    }

                    $progressBar->advance();

                    // Не перегружаем API
                    sleep(1);
                }

                $progressBar->setMessage('Завершено');
                $progressBar->finish();
                $this->newLine(); // Переход на новую строку после прогресс-бара
            }

            // Закрываем writer в try/catch
            try {
                $writer->close();
                $opened = false;
            } catch (\Throwable $e) {
                $this->warn("Ошибка при закрытии файла: {$e->getMessage()}");
                Log::error('GetVacancyByCurl: Spout close error: ' . $e->getMessage());
            }

            // Права на файл (если нужно)
            try {
                @chmod($filename, 0644);
            } catch (\Throwable $e) {
                // игнорируем
            }

            $this->info("Файл сохранён: {$filename}");
            $this->info("Обработано вакансий: {$count}");
            Log::info('GetVacancyByCurl finished', ['file' => $filename, 'count' => $count]);
        } catch (\Throwable $e) {
            $this->error("Фатальная ошибка: {$e->getMessage()}");
            Log::error('GetVacancyByCurl fatal', ['exception' => $e]);
            // попытка закрыть writer, если открыт
            if ($opened && $writer !== null) {
                try {
                    $writer->close();
                } catch (\Throwable $ex) {
                    // noop
                }
            }

            return 1;
        }

        return 0;
    }

    // --- Устойчивый HTTP-запрос ---
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

    private function extractSchedule(array $schedule): string
    {
        return implode('; ', array_column($schedule, 'name') ?: []);
    }

    private function extractTags(array $tags): string
    {
        return implode('; ', array_column($tags, 'title') ?: []);
    }

    private function extractSkills(array $skills): string
    {
        return implode('; ', array_column($skills, 'name') ?: []);
    }

    private function extractEducations(array $educations): string
    {
        $map = [
            'secondary' => 'Среднее',
            'special_secondary' => 'Среднее специальное',
            'unfinished_higher' => 'Неоконченное высшее',
            'higher' => 'Высшее',
            'bachelor' => 'Бакалавр',
            'master' => 'Магистр',
            'candidate' => 'Кандидат наук',
            'doctor' => 'Доктор наук',
        ];

        if (!is_array($educations)) {
            return '';
        }

        return implode('; ', array_map(fn($e) => $map[$e] ?? $e, $educations));
    }
}
