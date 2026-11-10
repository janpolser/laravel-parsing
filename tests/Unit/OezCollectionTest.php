<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->oezOriginalStoragePath = storage_path();
    $this->oezStoragePath = sys_get_temp_dir().'/oez-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->oezStoragePath);
    config(['services.hh.api_token' => 'test-token']);
    $this->oezOutputPath = storage_path('app/public/oez/oez_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->oezOriginalStoragePath);
    File::deleteDirectory($this->oezStoragePath);
});

function oezItem(string $id = '123456789'): array
{
    return [
        'id' => $id,
        'name' => 'Инженер',
        'alternate_url' => 'https://hh.ru/vacancy/'.$id,
        'published_at' => '2026-11-05T10:00:00+0300',
        'snippet' => ['responsibility' => 'Проектировать', 'requirement' => 'Опыт от 1 года'],
        'salary' => ['from' => 100000, 'to' => 150000, 'currency' => 'RUR'],
        'address' => ['raw' => 'Елабуга', 'lat' => 55.7, 'lng' => 52.0],
        'experience' => ['name' => 'От 1 года до 3 лет'],
        'professional_roles' => [['name' => 'Инженер']],
        'employer' => ['name' => 'Алабуга'],
    ];
}

function oezResponse(array $items, int $page = 0, int $pages = 1, int $found = 1): array
{
    return ['items' => $items, 'page' => $page, 'pages' => $pages, 'per_page' => count($items), 'found' => $found];
}

test('oez collects list API without detail requests and writes feed', function () {
    Http::fake(function (Request $request) {
        expect($request->hasHeader('Authorization', 'Bearer test-token'))->toBeTrue();

        return Http::response(oezResponse([oezItem()]));
    });

    $this->artisan('oez:collect-vacancies')->assertSuccessful();

    $xml = simplexml_load_file($this->oezOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(1)
        ->and((string) $xml->vacancies->vacancy->{'job-name'})->toBe('Инженер')
        ->and((string) $xml->vacancies->vacancy->description)->toContain('Проектировать');
    Http::assertSentCount(1);
});

test('oez preserves previous feed when API token is missing', function () {
    config(['services.hh.api_token' => null]);
    File::ensureDirectoryExists(dirname($this->oezOutputPath));
    File::put($this->oezOutputPath, '<source>previous</source>');

    $this->artisan('oez:collect-vacancies')->assertFailed();

    expect(File::get($this->oezOutputPath))->toBe('<source>previous</source>');
    Http::assertNothingSent();
});
