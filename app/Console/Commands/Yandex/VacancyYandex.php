<?php

namespace App\Console\Commands\Yandex;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class VacancyYandex extends Command
{
    protected $signature = 'app:vacancy-yandex';

    protected $description = 'Command description';

    public function handle()
    {
        $response = Http::withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
            'Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8',
            'Accept-Encoding' => 'gzip, deflate, br',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'Upgrade-Insecure-Requests' => '1',
            'Sec-Fetch-Dest' => 'document',
            'Sec-Fetch-Mode' => 'navigate',
            'Sec-Fetch-Site' => 'none',
            'Sec-Fetch-User' => '?1',
            'sec-ch-ua' => '"Not_A Brand";v="8", "Chromium";v="120", "Google Chrome";v="120"',
            'sec-ch-ua-mobile' => '?0',
            'sec-ch-ua-platform' => '"Windows"',
        ])
            ->withOptions([
                'verify' => false,
                'timeout' => 30,
            ])
            ->get('https://crowd.yandex.ru/vacancies');

        if ($response->successful()) {
            $html = $response->body();

            if (preg_match('/<script id="data"[^>]*>([\s\S]*?)<\/script>/', $html, $matches)) {
                $json = trim($matches[1]);
                file_put_contents(storage_path('app/vacancies.json'), $this->normalizeJson($json));
                $this->info('Данные получены!');
            } else {
                $this->error('JSON не найден в ответе');
                file_put_contents(storage_path('app/debug.html'), $html);
                $this->info('HTML сохранен в storage/app/debug.html для отладки');
            }
        } else {
            $this->error('HTTP Error: ' . $response->status());
        }

        return 0;
    }

    private function normalizeJson(string $json): string
    {
        $data = json_decode($json, true);

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
