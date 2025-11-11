<?php

namespace App\Console\Commands\Yandex;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VacancyYandex extends Command
{
    protected $signature = 'app:vacancy-yandex';

    protected $description = 'Command description';

//    public function handle()
//    {
//        $response = Http::get('https://crowd.yandex.ru/vacancies');
//        $html = $response->body();
//
//        // Простой поиск по id="data"
//        if (preg_match('/<script id="data"[^>]*>([\s\S]*?)<\/script>/', $html, $matches)) {
//            $json = trim($matches[1]);
//            $this->info('JSON найден!');
//
//            // Выводим JSON
//            $this->line($json);
//
//            // Сохраняем в файл
//            file_put_contents(storage_path('app/vacancies.json'), $json);
//            $this->info('Сохранено в storage/app/vacancies.json');
//
//        } else {
//            $this->error('JSON не найден');
//        }
//
//        return 0;
//    }

//    public function handle()
//    {
//        // Попробуем с разными заголовками как у реального браузера
//        $response = Http::withHeaders([
//            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
//            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
//            'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
//            'Accept-Encoding' => 'gzip, deflate, br',
//            'Cache-Control' => 'no-cache',
//            'Connection' => 'keep-alive',
//            'Upgrade-Insecure-Requests' => '1',
//            'Sec-Fetch-Dest' => 'document',
//            'Sec-Fetch-Mode' => 'navigate',
//            'Sec-Fetch-Site' => 'none',
//            'Sec-Fetch-User' => '?1',
//            'sec-ch-ua' => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
//            'sec-ch-ua-mobile' => '?0',
//            'sec-ch-ua-platform' => '"Windows"',
//        ])
//            ->withOptions([
//                'verify' => false, // отключаем SSL verify (осторожно!)
//                'timeout' => 30,
//            ])
//            ->get('https://crowd.yandex.ru/vacancies');
//
//        if ($response->successful()) {
//            $html = $response->body();
//
//            if (preg_match('/<script id="data"[^>]*>([\s\S]*?)<\/script>/', $html, $matches)) {
//                $json = trim($matches[1]);
//                file_put_contents(storage_path('app/vacancies.json'), $json);
//                $this->info('Данные получены!');
//                $this->line($json);
//            } else {
//                $this->error('JSON не найден в ответе');
//                file_put_contents(storage_path('app/debug.html'), $html);
//                $this->info('HTML сохранен в storage/app/debug.html для отладки');
//            }
//        } else {
//            $this->error('HTTP Error: ' . $response->status());
//        }
//
//        return 0;
//    }

//    public function handle()
//    {
//        $response = Http::get('https://crowd.yandex.ru/vacancies');
//        $html = $response->body();
//
////         Простой поиск по id="data"
//        if (preg_match('/<script id="data"[^>]*>([\s\S]*?)<\/script>/', $html, $matches)) {
//            $json = trim($matches[1]);
//            $this->info('JSON найден!');
//
//            // Выводим JSON
//            $this->line($json);
//
//            // Сохраняем в файл как сырой JSON
//            file_put_contents(storage_path('app/vacancies.json'), $json);
//            $this->info('Сохранено в storage/app/vacancies.json');
//
//            // Дополнительно: сохраняем в формате с метаданными
//            $dataWithMeta = [
//                'parsed_at' => now()->toISOString(),
//                'source' => 'https://crowd.yandex.ru/vacancies',
//                'data' => json_decode($json, true) // преобразуем в массив и обратно для красивого форматирования
//            ];
//
//            file_put_contents(
//                storage_path('app/vacancies_formatted.json'),
//                json_encode($dataWithMeta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
//            );
//            $this->info('Форматированная версия сохранена в storage/app/vacancies_formatted.json');
//
//        } else {
//            $this->error('JSON не найден');
//        }
//
//        return 0;
//    }

//    public function handle()
//    {
//        // Случайная задержка от 1 до 3 секунд
//        sleep(rand(1, 3));
//
//        $response = Http::timeout(30)
//            ->withHeaders([
//                'User-Agent' => $this->getRandomUserAgent(),
//                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
//                'Accept-Language' => 'ru-RU,ru;q=0.8,en-US;q=0.5,en;q=0.3',
//                'Accept-Encoding' => 'gzip, deflate, br',
//                'Connection' => 'keep-alive',
//                'Upgrade-Insecure-Requests' => '1',
//            ])
//            ->get('https://crowd.yandex.ru/vacancies');
//
//        if (!$response->successful()) {
//            $this->error('HTTP Error: ' . $response->status());
//            return 1;
//        }
//
//        $html = $response->body();
//
//        if (preg_match('/<script id="data"[^>]*>([\s\S]*?)<\/script>/', $html, $matches)) {
//            $json = trim($matches[1]);
//            $this->info('JSON найден!');
//
//            file_put_contents(storage_path('app/vacancies.json'), $json);
//            $this->info('Сохранено в storage/app/vacancies.json');
//
//        } else {
//            $this->error('JSON не найден');
//        }
//
//        return 0;
//    }
//
//    private function getRandomUserAgent(): string
//    {
//        $agents = [
//            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
//            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
//            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
//            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:109.0) Gecko/20100101 Firefox/121.0',
//        ];
//
//        return $agents[array_rand($agents)];
//    }
}
