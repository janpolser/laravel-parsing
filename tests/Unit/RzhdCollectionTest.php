<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->rzhdOriginalStoragePath = storage_path();
    $this->rzhdStoragePath = sys_get_temp_dir().'/rzhd-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->rzhdStoragePath);
    $this->rzhdOutputPath = storage_path('app/public/rzhd/rzhd_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->rzhdOriginalStoragePath);
    File::deleteDirectory($this->rzhdStoragePath);
});

function rzhdListPage(array $ids, int $page, int $count, int $pages): array
{
    return [
        'meta' => ['count' => $count, 'page' => $page, 'pages' => $pages],
        'data' => array_map(fn (int $id) => [
            'id' => $id,
            'position_title' => 'Машинист',
            'published_at' => '2026-10-27T07:00:00+03:00',
        ], $ids),
    ];
}

test('rzhd publishes every page including the final short page', function () {
    Http::fake([
        'https://team.rzd.ru/api/v1/career/vacancies?*' => Http::sequence()
            ->push(rzhdListPage(range(1, 500), 1, 501, 2))
            ->push(rzhdListPage([501], 2, 501, 2)),
        'https://team.rzd.ru/api/v1/career/vacancies/*' => Http::response([
            'responsibilities' => 'Обеспечивать движение поездов',
        ]),
    ]);

    $this->artisan('rzhd:collect-vacancies')->assertSuccessful();

    $xml = simplexml_load_file($this->rzhdOutputPath);
    $urls = array_map(fn ($vacancy) => (string) $vacancy->url, iterator_to_array($xml->vacancies->vacancy, false));
    expect($urls)->toHaveCount(501)
        ->and(count(array_unique($urls)))->toBe(501)
        ->and($urls[500])->toBe('https://team.rzd.ru/career/vacancies/501');

    $requests = Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/api/v1/career/vacancies');
    expect($requests->map(fn ($pair) => (int) $pair[0]['page'])->all())->toBe([1, 2]);
    expect($requests->every(fn ($pair) => (int) $pair[0]['per_page'] === 500))->toBeTrue();
});

test('rzhd leaves the previous feed intact when the list is incomplete', function (array $secondPage, int $status) {
    File::ensureDirectoryExists(dirname($this->rzhdOutputPath));
    file_put_contents($this->rzhdOutputPath, '<source>previous feed</source>');

    Http::fake([
        'https://team.rzd.ru/api/v1/career/vacancies?*' => Http::sequence()
            ->push(rzhdListPage(range(1, 500), 1, 502, 2))
            ->push($secondPage, $status),
    ]);

    $this->artisan('rzhd:collect-vacancies')->assertFailed();

    expect(file_get_contents($this->rzhdOutputPath))->toBe('<source>previous feed</source>');
    expect(glob(dirname($this->rzhdOutputPath).'/*.tmp'))->toBe([]);
    Http::assertSentCount(2);
})->with([
    'HTTP error' => [[], 503],
    'empty page' => [rzhdListPage([], 2, 502, 2), 200],
    'short catalogue' => [rzhdListPage([501], 2, 502, 2), 200],
    'duplicate vacancy ID across pages' => [rzhdListPage([500, 501], 2, 502, 2), 200],
    'API repeats the first page' => [rzhdListPage([501, 502], 1, 502, 2), 200],
    'catalogue changes while paging' => [rzhdListPage([501, 502], 2, 503, 2), 200],
    'page metadata is missing' => [['data' => [['id' => 501]]], 200],
    'page metadata is not an object' => [['meta' => 'invalid', 'data' => [['id' => 501]]], 200],
    'vacancy ID is missing' => [[
        'meta' => ['count' => 502, 'page' => 2, 'pages' => 2],
        'data' => [['position_title' => 'Машинист']],
    ], 200],
]);
