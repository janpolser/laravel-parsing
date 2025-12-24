<?php

namespace App\Console\Commands\Pyaterochka;

use App\Services\YandexFeedXmlFormat;
use DateTime;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class GetVacancyByCurl extends Command
{
    protected $signature = 'app:vacancy-5ka';

    protected $description = 'Command description';

    public function handle(YandexFeedXmlFormat $xml)
    {
        $limit = 1000;
        $timeout = 60;

        date_default_timezone_set('Europe/Moscow');
        $date = new DateTime;

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

            // Кэш для хранения описаний по названию вакансии
            $descriptionCache = [];

            // Статистика
            $cacheHits = 0;
            $cacheMisses = 0;

            $editedVacancies = [];

            $this->info("Всего страниц для обработки: {$pages}");

            $progressBar = $this->output->createProgressBar($pages);
            $progressBar->start();

            for ($i = 1; $i <= $pages; $i++) {
                try {
                    $response = Http::timeout($timeout)
                        ->retry(3, 1000)
                        ->get("https://rabota5ka.ru/api/vacancy/hire-request?page={$i}&limit={$limit}");

                    if ($response->failed()) {
                        $this->warn("Ошибка при запросе страницы $i");
                        $progressBar->advance();
                        continue;
                    }

                    $data = json_decode($response->body(), true);

                    if (!isset($data['items'])) {
                        $this->warn("Нет данных на странице $i");
                        $progressBar->advance();
                        continue;
                    }

                    foreach ($data['items'] as $vacancy) {
                        $vacancyName = $vacancy['vacancy']['name'] ?? 'Без названия';

                        // Ключ для кэша - название вакансии
                        $cacheKey = $vacancyName;

                        // Проверяем, есть ли описание в кэше
                        if (isset($descriptionCache[$cacheKey])) {
                            $description = $descriptionCache[$cacheKey];
                            $cacheHits++;
                        } else {
                            // Загружаем описание
                            $description = $this->getVacancyDescription($vacancy['id']);
                            // Сохраняем в кэш под ключом - название вакансии
                            $descriptionCache[$cacheKey] = $description;
                            $cacheMisses++;

                            // Пауза между запросами описаний
                            sleep(1);
                        }

                        $editedVacancy['url'] = 'https://rabota5ka.ru/api/vacancy/hire-request/' . $vacancy['id'];
                        $editedVacancy['mobile_url'] = $editedVacancy['url'];
                        $editedVacancy['creation_date'] = $date->format('Y-m-d H:i:s') . ' GMT+3';

                        // Зарплата
                        $salaryFrom = $vacancy['salaryFrom'] ?? '';
                        $salaryTo = $vacancy['salaryTo'] ?? '';
                        if ($salaryFrom && $salaryTo) {
                            $editedVacancy['salary'] = $salaryFrom . ' - ' . $salaryTo;
                        } elseif ($salaryFrom) {
                            $editedVacancy['salary'] = 'от ' . $salaryFrom;
                        } elseif ($salaryTo) {
                            $editedVacancy['salary'] = 'до ' . $salaryTo;
                        } else {
                            $editedVacancy['salary'] = '';
                        }

                        $editedVacancy['currency'] = 'RUB';
                        $editedVacancy['category']['industry'] = $vacancy['vacancy']['jobDirection'] ?? '';
                        $editedVacancy['job_name'] = $vacancyName;
                        $editedVacancy['employment'] = $vacancy['schedule'][0] ?? '';
                        $editedVacancy['schedule'] = $vacancy['rawSchedule'][0] ?? '';
                        $editedVacancy['description'] = $description;
                        $editedVacancy['addresses']['address']['location'] = $vacancy['address'] ?? '';
                        $editedVacancy['company_name'] = '5ka';
                        $editedVacancy['hr_agency'] = 'false';

                        $editedVacancies[] = $editedVacancy;
                    }

                } catch (ConnectionException $e) {
                    $this->warn("Таймаут на странице $i");
                } catch (\Exception $e) {
                    $this->warn("Ошибка на странице $i: " . $e->getMessage());
                }

                $progressBar->advance();

                // Пауза между страницами
                if ($i < $pages) {
                    sleep(2);
                }
            }

            $progressBar->finish();
            $this->newLine(2);

            // Выводим информацию о кэше
            $this->info("📊 Статистика кэширования описаний:");
            $this->info("  Уникальных вакансий в кэше: " . count($descriptionCache));
            $this->info("  Запросов к API (промахи): {$cacheMisses}");
            $this->info("  Использований кэша (попадания): {$cacheHits}");

            // Можно вывести содержимое кэша для отладки
            if ($this->output->isVerbose()) {
                $this->line("\nСодержимое кэша описаний:");
                foreach ($descriptionCache as $name => $desc) {
                    $this->line("  '{$name}' => длина: " . strlen($desc) . " символов");
                }
            }

            $xml->createXmlFeed($editedVacancies, 'https://rabota5ka.ru/', 'storage/app/public/5ka/PyaterochkaVacancies' . today() . '.xml');

            $this->info("✅ Готово! Обработано вакансий: " . count($editedVacancies));

        } catch (ConnectionException $e) {
            $this->error('Таймаут при подключении к API: ' . $e->getMessage());
            return Command::FAILURE;
        } catch (\Exception $e) {
            $this->error('Неожиданная ошибка: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return 0;
    }

    private function getVacancyDescription($vacancyId)
    {
        try {
            $response = Http::timeout(60)
                ->get('https://rabota5ka.ru/api/vacancy/hire-request/' . $vacancyId);

            if ($response->failed()) {
                $this->warn("Ошибка при получении описания ID: {$vacancyId}");
                return '';
            }

            $data = json_decode($response->body(), true);

            if (isset($data['description'])) {
                return $this->extractTextFromJsonStructure($data['description']);
            }

            return '';

        } catch (\Exception $e) {
            $this->warn("Ошибка при запросе описания ID {$vacancyId}");
            return '';
        }
    }

    private function extractTextFromJsonStructure(string $jsonText): string
    {
        try {
            $data = json_decode($jsonText, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $this->cleanupText($jsonText);
            }

            $result = [];

            foreach ($data as $item) {
                if (isset($item['type']) && $item['type'] === 'ul' && isset($item['children'])) {
                    // Это список - извлекаем элементы
                    $listItems = [];
                    foreach ($item['children'] as $listItem) {
                        if (isset($listItem['children'])) {
                            $itemText = '';
                            foreach ($listItem['children'] as $child) {
                                if (isset($child['text'])) {
                                    $itemText .= $child['text'];
                                }
                            }
                            $itemText = trim($itemText);
                            if (!empty($itemText)) {
                                $listItems[] = $itemText;
                            }
                        }
                    }

                    if (!empty($listItems)) {
                        // Проверяем предыдущий элемент - если это заголовок, объединяем
                        if (!empty($result)) {
                            $lastIndex = count($result) - 1;
                            if (str_ends_with($result[$lastIndex], ':')) {
                                $result[$lastIndex] .= '<br>' . implode(';<br>', $listItems);
                                continue;
                            }
                        }
                        $result[] = implode(';<br>', $listItems);
                    }
                } elseif (isset($item['children'])) {
                    // Это текстовый блок
                    $text = '';
                    foreach ($item['children'] as $child) {
                        if (isset($child['text'])) {
                            $text .= $child['text'];
                        }
                    }

                    $text = trim($text);
                    if (!empty($text)) {
                        // Проверяем, нужно ли объединять с предыдущим заголовком
                        if (!empty($result) && str_ends_with(end($result), ':')) {
                            $result[count($result) - 1] .= ' ' . $text;
                        } else {
                            $result[] = $text;
                        }
                    }
                }
            }

            // Объединяем все части
            $formattedText = implode('<br>', $result);

            // Очищаем и возвращаем
            return $this->cleanupSpecialCharacters($formattedText);

        } catch (\Exception $e) {
            return $this->cleanupText($jsonText);
        }
    }

    private function cleanupText(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $nbspVariants = [
            "\xC2\xA0", "\xE2\x80\xAF", "\x00A0", "\u{00A0}", "\u{202F}",
            '&nbsp;', '&#160;', '&#xa0;', '&#xA0;',
        ];
        $text = str_replace($nbspVariants, ' ', $text);

        $text = strip_tags($text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim($text);
    }

    private function cleanupSpecialCharacters(string $text): string
    {
        $nbspVariants = [
            "\xC2\xA0", "\xE2\x80\xAF", "\x00A0", "\u{00A0}", "\u{202F}",
            '&nbsp;', '&#160;', '&#xa0;', '&#xA0;',
        ];

        $text = str_replace($nbspVariants, ' ', $text);
        return trim($text);
    }
}
