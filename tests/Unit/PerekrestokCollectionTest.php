<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->perekrestokWorkingDirectory = sys_get_temp_dir() . '/perekrestok-feed-' . bin2hex(random_bytes(8));
    mkdir($this->perekrestokWorkingDirectory);
    $this->perekrestokPreviousDirectory = getcwd();
    chdir($this->perekrestokWorkingDirectory);
});

afterEach(function () {
    chdir($this->perekrestokPreviousDirectory);
    File::deleteDirectory(storage_path('app/public/perekrestok'));
    File::deleteDirectory($this->perekrestokWorkingDirectory);
});

test('perekrestok collects all pages from the cross portal and publishes XML', function () {
    Http::fake(function ($request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $page = (int) ($query['page'] ?? 0);
        $items = $page === 1 ? [[
            'id' => 'request-1',
            'name' => 'Кассир',
            'jobDirection' => 'stores',
            'description' => '[]',
            'vacancy' => [
                'name' => 'Кассир',
                'jobDirection' => 'stores',
                'description' => '[{"children":[{"text":"Работа с покупателями"}]}]',
            ],
            'address' => 'Москва, улица Тестовая',
            'city' => ['name' => 'Москва'],
            'schedule' => ['full-time'],
            'position' => ['coordinates' => [37.6, 55.7]],
        ]] : [[
            'id' => 'request-2',
            'name' => 'Пекарь',
            'jobDirection' => 'production',
            'vacancy' => [
                'description' => '[{"children":[{"text":"Выпечка продукции"}]}]',
            ],
            'address' => 'Москва, улица Вторая',
            'city' => ['name' => 'Москва'],
            'schedule' => ['shift'],
        ]];

        return Http::response([
            'items' => $items,
            'limit' => 1,
            'page' => $page,
            'total' => 2,
            'totalPages' => 2,
        ]);
    });

    $this->artisan('perekrestok:collect-vacancies --limit=1')->assertSuccessful();

    $files = glob(storage_path('app/public/perekrestok/*.xml'));
    expect($files)->toHaveCount(1);
    $xml = file_get_contents($files[0]);
    expect(substr_count($xml, '<vacancy>'))->toBe(2)
        ->and($xml)->toContain('https://rabota.perekrestok.ru/vacancies/request-1')
        ->and($xml)->toContain('Работа с покупателями')
        ->and($xml)->not->toContain('Описание вакансии:')
        ->and($xml)->not->toContain('campaign>Перекрёсток #')
        ->and($xml)->toContain('<industry>Магазины</industry>');
    Http::assertSentCount(2);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'portals%5B0%5D=cross'));
});

test('perekrestok does not publish a partial feed when pagination is inconsistent', function () {
    Http::fake([
        'https://rabota5ka.ru/gw/api/vacancy/hire-request*' => Http::response([
            'items' => [],
            'limit' => 100,
            'page' => 1,
            'total' => 101,
            'totalPages' => 1,
        ]),
    ]);

    $this->artisan('perekrestok:collect-vacancies')->assertFailed();
    expect(glob(storage_path('app/public/perekrestok/*.xml')))->toBe([]);
});
