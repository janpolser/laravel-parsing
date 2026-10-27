<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->wbOriginalStoragePath = storage_path();
    $this->wbStoragePath = sys_get_temp_dir().'/wb-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->wbStoragePath);
    $this->wbOutputPath = storage_path('app/public/wb/wb_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->wbOriginalStoragePath);
    File::deleteDirectory($this->wbStoragePath);
});

function wbCollectionPage(array $ids, int $offset, int $total, int $limit = 500): array
{
    return [
        'status' => 200,
        'data' => [
            'items' => array_map(fn (int $id) => [
                'id' => $id,
                'name' => 'Менеджер',
                'city_title' => 'Москва',
            ], $ids),
            'range' => ['count' => $total, 'limit' => $limit, 'offset' => $offset],
        ],
    ];
}

function wbCollectionDetail(Request $request)
{
    $id = (int) basename(parse_url($request->url(), PHP_URL_PATH));

    return Http::response([
        'status' => 200,
        'data' => ['id' => $id, 'description' => "Обязанности вакансии {$id}"],
    ]);
}

test('wb paginates by the returned page size and keeps details distinct for equal titles', function () {
    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::sequence()
            ->push(wbCollectionPage([1, 2], 0, 5))
            ->push(wbCollectionPage([3, 4], 2, 5))
            ->push(wbCollectionPage([5], 4, 5)),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/*' => fn (Request $request) => wbCollectionDetail($request),
    ]);

    $this->artisan('wb:collect-wb-vacancies', ['--limit' => 500])->assertSuccessful();

    $xml = simplexml_load_file($this->wbOutputPath);
    $vacancies = iterator_to_array($xml->vacancies->vacancy, false);
    $urls = array_map(fn ($vacancy) => (string) $vacancy->url, $vacancies);
    expect($urls)->toHaveCount(5)
        ->and(count(array_unique($urls)))->toBe(5)
        ->and($urls[4])->toBe('https://career.wb.ru/vacancies/5');

    foreach ($vacancies as $index => $vacancy) {
        expect((string) $vacancy->description)->toBe('Обязанности вакансии '.($index + 1));
    }

    $listRequests = Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/crm-api/api/v1/pub/vacancies');
    expect($listRequests->map(fn ($pair) => (int) $pair[0]['offset'])->values()->all())->toBe([0, 2, 4]);
    expect($listRequests->every(fn ($pair) => (int) $pair[0]['limit'] === 500))->toBeTrue();

    $detailRequests = Http::recorded(fn ($request) => preg_match('~/crm-api/api/v1/pub/vacancies/\d+$~', $request->url()));
    expect($detailRequests)->toHaveCount(5);
});

test('wb allows an explicitly limited preview without fetching subsequent pages', function () {
    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::sequence()
            ->push(wbCollectionPage([1, 2], 0, 5, 50)),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/*' => fn (Request $request) => wbCollectionDetail($request),
    ]);

    $this->artisan('wb:collect-wb-vacancies', [
        '--max-pages' => 1,
        '--outfile' => 'wb_preview_',
    ])->assertSuccessful();

    $previewPath = storage_path('app/public/wb/wb_preview_'.today()->toDateString().'.xml');
    $xml = simplexml_load_file($previewPath);
    expect($xml->vacancies->vacancy)->toHaveCount(2)
        ->and(file_exists($this->wbOutputPath))->toBeFalse();
    $listRequests = Http::recorded(fn ($request) => parse_url($request->url(), PHP_URL_PATH) === '/crm-api/api/v1/pub/vacancies');
    expect($listRequests)->toHaveCount(1)
        ->and((int) $listRequests->first()[0]['limit'])->toBe(50);
});

test('wb never overwrites the canonical feed with an explicitly partial run', function (array $options, array $page) {
    File::ensureDirectoryExists(dirname($this->wbOutputPath));
    file_put_contents($this->wbOutputPath, '<source>previous feed</source>');

    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::response($page),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/*' => fn (Request $request) => wbCollectionDetail($request),
    ]);

    $this->artisan('wb:collect-wb-vacancies', $options)->assertFailed();

    expect(file_get_contents($this->wbOutputPath))->toBe('<source>previous feed</source>')
        ->and(glob(dirname($this->wbOutputPath).'/*.tmp'))->toBe([]);
    Http::assertNotSent(fn ($request) => preg_match('~/crm-api/api/v1/pub/vacancies/\d+$~', $request->url()));
})->with([
    'page cap stops before the catalogue ends' => [['--max-pages' => 1], wbCollectionPage([1, 2], 0, 4)],
    'starting offset omits earlier vacancies' => [['--start-offset' => 2], wbCollectionPage([3, 4], 2, 4)],
]);

test('wb preserves the previous feed when pagination is incomplete or invalid', function (array $secondPage, int $status) {
    File::ensureDirectoryExists(dirname($this->wbOutputPath));
    file_put_contents($this->wbOutputPath, '<source>previous feed</source>');

    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::sequence()
            ->push(wbCollectionPage([1, 2], 0, 4))
            ->push($secondPage, $status),
    ]);

    $this->artisan('wb:collect-wb-vacancies')->assertFailed();

    expect(file_get_contents($this->wbOutputPath))->toBe('<source>previous feed</source>')
        ->and(glob(dirname($this->wbOutputPath).'/*.tmp'))->toBe([]);
    Http::assertSentCount(3);
})->with([
    'HTTP failure' => [[], 503],
    'empty page before total is reached' => [wbCollectionPage([], 2, 4), 200],
    'duplicate ID across pages' => [wbCollectionPage([2, 3], 2, 4), 200],
    'repeated page' => [wbCollectionPage([1, 2], 2, 4), 200],
    'response offset differs from requested offset' => [wbCollectionPage([3, 4], 0, 4), 200],
    'total changes during pagination' => [wbCollectionPage([3, 4], 2, 5), 200],
    'range metadata is missing' => [['status' => 200, 'data' => ['items' => [['id' => 3, 'name' => 'Менеджер']]]], 200],
    'range metadata is not an object' => [['status' => 200, 'data' => ['items' => [], 'range' => 'invalid']], 200],
    'items is not an array' => [['status' => 200, 'data' => ['items' => 'invalid', 'range' => ['count' => 4, 'offset' => 2]]], 200],
    'vacancy ID is missing' => [[
        'status' => 200,
        'data' => ['items' => [['name' => 'Менеджер']], 'range' => ['count' => 4, 'offset' => 2]],
    ], 200],
]);

test('wb rejects invalid pagination options before making requests', function (array $options) {
    $this->artisan('wb:collect-wb-vacancies', $options)->assertExitCode(2);

    Http::assertNothingSent();
    expect(file_exists($this->wbOutputPath))->toBeFalse();
})->with([
    'negative offset' => [['--start-offset' => -1]],
    'negative page cap' => [['--max-pages' => -1]],
    'zero page size' => [['--limit' => 0]],
    'page size exceeds supported range' => [['--limit' => 1001]],
]);

test('wb skips a confirmed missing card and exports the remaining vacancy', function () {
    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::response(wbCollectionPage([1, 2], 0, 2)),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/1' => Http::response([], 404),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/2' => Http::response([
            'status' => 200,
            'data' => ['id' => 2, 'description' => 'Обязанности второй вакансии'],
        ]),
    ]);

    $this->artisan('wb:collect-wb-vacancies')
        ->expectsOutputToContain('404')
        ->assertSuccessful();

    $xml = simplexml_load_file($this->wbOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(1)
        ->and((string) $xml->vacancies->vacancy[0]->url)->toBe('https://career.wb.ru/vacancies/2')
        ->and((string) $xml->vacancies->vacancy[0]->description)->toBe('Обязанности второй вакансии');
    Http::assertSentCount(4);
});

test('wb preserves the previous feed when all listed cards return not found', function () {
    File::ensureDirectoryExists(dirname($this->wbOutputPath));
    file_put_contents($this->wbOutputPath, '<source>previous feed</source>');

    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::response(wbCollectionPage([1, 2], 0, 2)),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/*' => Http::response([], 404),
    ]);

    $this->artisan('wb:collect-wb-vacancies')->assertFailed();

    expect(file_get_contents($this->wbOutputPath))->toBe('<source>previous feed</source>')
        ->and(glob(dirname($this->wbOutputPath).'/*.tmp'))->toBe([]);
    Http::assertSentCount(4);
});

test('wb preserves the previous feed when a vacancy detail cannot be published', function (array $detail, int $status) {
    File::ensureDirectoryExists(dirname($this->wbOutputPath));
    file_put_contents($this->wbOutputPath, '<source>previous feed</source>');

    Http::fake([
        'https://career.wb.ru/' => Http::response('<html/>'),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies?*' => Http::response(wbCollectionPage([1, 2], 0, 2)),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/1' => Http::response(['status' => 200, 'data' => ['id' => 1, 'description' => 'Обязанности первой вакансии']]),
        'https://career.wb.ru/crm-api/api/v1/pub/vacancies/2' => Http::response($detail, $status),
    ]);

    $this->artisan('wb:collect-wb-vacancies')->assertFailed();

    expect(file_get_contents($this->wbOutputPath))->toBe('<source>previous feed</source>')
        ->and(glob(dirname($this->wbOutputPath).'/*.tmp'))->toBe([]);
})->with([
    'HTTP failure' => [[], 503],
    'HTTP forbidden is not treated as a deleted card' => [[], 403],
    'missing description' => [['status' => 200, 'data' => ['id' => 2]], 200],
    'description contains only a URL removed by XML formatting' => [[
        'status' => 200,
        'data' => ['id' => 2, 'description' => 'https://example.org/vacancy/2'],
    ], 200],
]);
