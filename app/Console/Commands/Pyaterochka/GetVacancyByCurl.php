<?php

namespace App\Console\Commands\Pyaterochka;

use Box\Spout\Writer\Common\Creator\WriterEntityFactory;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GetVacancyByCurl extends Command
{
    protected $signature = 'app:vacancy-5ka';

    protected $description = 'Command description';

    public function handle()
    {
        // Уменьшаем лимит и добавляем таймауты
        $limit = 1000; // Уменьшаем количество записей на страницу
        $timeout = 60; // Увеличиваем таймаут до 60 секунд

        try {
            $response = Http::timeout($timeout)
                ->get("https://rabota5ka.ru/api/vacancy/hire-request?page=1&limit={$limit}");

            if ($response->failed()) {
                $this->error('Ошибка при получении данных: ' . $response->status());

                return Command::FAILURE;
            }

            $html = $response->body();
            $data = json_decode($html, true);

            if (!isset($data['totalPages'])) {
                $this->error('Некорректный ответ от API');

                return Command::FAILURE;
            }

            $pages = $data['totalPages'];

            // Создаем writer для XLSX
            $writer = WriterEntityFactory::createXLSXWriter();
            $filePath = storage_path('app/vacancies_5ka.xlsx');
            $writer->openToFile($filePath);

            // Записываем заголовки
            $headerRow = WriterEntityFactory::createRowFromArray([
                'ID',
                'URL',
                'Название',
                'Город',
                'Адрес',
                'Зарплата от',
                'Зарплата до',
                'График работы',
                'Сырое расписание',
                'Направление работы',
                'Внешний ID',
                'Тип синхронизации',
                'Широта',
                'Долгота',
            ]);
            $writer->addRow($headerRow);

            $totalVacancies = 0;

            for ($i = 1; $i <= $pages; $i++) {
                $this->info("Обрабатывается страница $i из $pages...");

                try {
                    $response = Http::timeout($timeout)
                        ->retry(3, 1000) // 3 попытки с задержкой 1 секунда
                        ->get("https://rabota5ka.ru/api/vacancy/hire-request?page={$i}&limit={$limit}");

                    if ($response->failed()) {
                        $this->error("Ошибка при запросе страницы $i: " . $response->status());

                        continue;
                    }

                    $html = $response->body();
                    $data = json_decode($html, true);

                    if (!isset($data['items'])) {
                        $this->error("Нет данных items на странице $i");

                        continue;
                    }

                    $vacancies = $data['items'];

                    foreach ($vacancies as $vacancy) {
                        // Подготавливаем данные для записи
                        $rowData = [
                            $vacancy['id'] ?? '',
                            'https://rabota5ka.ru/vacancy/' . $vacancy['id'],
                            $vacancy['name'] ?? '',
                            $vacancy['city']['name'] ?? '',
                            $vacancy['address'] ?? '',
                            $vacancy['salaryFrom'] ?? '',
                            $vacancy['salaryTo'] ?? '',
                            isset($vacancy['schedule']) ? implode(', ', $vacancy['schedule']) : '',
                            isset($vacancy['rawSchedule']) ? implode(', ', $vacancy['rawSchedule']) : '',
                            $vacancy['jobDirection'] ?? '',
                            $vacancy['externalId'] ?? '',
                            $vacancy['syncType'] ?? '',
                            $vacancy['position']['coordinates'][0] ?? '',
                            $vacancy['position']['coordinates'][1] ?? '',
                        ];

                        // Создаем и добавляем строку
                        $row = WriterEntityFactory::createRowFromArray($rowData);
                        $writer->addRow($row);

                        $totalVacancies++;
                    }

                    $this->info("Страница $i обработана. Найдено вакансий: " . count($vacancies));

                    // Пауза между запросами чтобы не нагружать сервер
                    if ($i < $pages) {
                        sleep(2);
                    }

                } catch (ConnectionException $e) {
                    $this->error("Таймаут при обработке страницы $i: " . $e->getMessage());

                    continue;
                } catch (\Exception $e) {
                    $this->error("Ошибка при обработке страницы $i: " . $e->getMessage());

                    continue;
                }
            }

            // Закрываем writer
            $writer->close();

            $this->info("Обработка завершена. Всего вакансий: $totalVacancies");
            $this->info("Файл успешно сохранен в: $filePath");

            return Command::SUCCESS;

        } catch (ConnectionException $e) {
            $this->error('Таймаут при подключении к API: ' . $e->getMessage());

            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Неожиданная ошибка: ' . $e->getMessage());

            return Command::FAILURE;
        }
    }
}
