<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->ozonOriginalStoragePath = storage_path();
    $this->ozonStoragePath = sys_get_temp_dir().'/ozon-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->ozonStoragePath);
    $this->ozonOutputPath = storage_path('app/public/ozon/ozon_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->ozonOriginalStoragePath);
    File::deleteDirectory($this->ozonStoragePath);
});

function ozonRow(string $uuid, string $type, string $title, string $department = 'Ozon Tech', int $hhId = 0): array
{
    return ['internalUuid' => $uuid, 'hhId' => $hhId, 'vacancyType' => $type, 'title' => $title,
        'department' => $department, 'city' => 'Москва', 'employment' => 'Полная',
        'experience' => 'От 1 года до 3 лет', 'workFormat' => ['Гибрид']];
}

function ozonPage(array $items, int $page, int $count, int $size): array
{
    return ['items' => $items, 'meta' => ['page' => $page, 'perPage' => $size, 'totalItems' => $count, 'totalPages' => (int) ceil($count / $size)]];
}

test('ozon excludes all hh identifiers before fetching details', function () {
    $external = ozonRow('1872eb11-cc54-4769-b488-a06e1c4ec67d', 'external_vacancy', 'На HH', hhId: 138780339);
    $internal = ozonRow('fdd52a53-63d5-4167-a1f1-e2cadc1a855f', 'internal_vacancy', 'Go-разработчик');
    $linkedInternal = ozonRow('06570187-117a-467f-8a0c-8160123325f5', 'internal_vacancy', 'Внутренняя с HH', hhId: 136849808);
    $virtual = ozonRow('b8c81b0f-a61a-436d-b623-d84e66e9e247', 'virtual_vacancy', 'Курьер');
    Http::fake(function (Request $request) use ($external, $internal, $linkedInternal, $virtual) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/vacancy') {
            return (int) $request['meta.page'] === 1
                ? Http::response(ozonPage([$external, $internal], 1, 4, 2))
                : Http::response(ozonPage([$linkedInternal, $virtual], 2, 4, 2));
        }
        if ($path === '/v2/vacancy/fdd52a53-63d5-4167-a1f1-e2cadc1a855f') {
            return Http::response(['name' => 'Go-разработчик', 'city' => 'Москва', 'description' => '<p>Разработка сервисов</p>']);
        }

        return Http::response([], 500);
    });

    $this->artisan('ozon:collect-vacancies', ['--sleep-ms' => 0])
        ->expectsOutputToContain('Исключено вакансий с hhId: 2')
        ->assertSuccessful();

    $xml = simplexml_load_file($this->ozonOutputPath);
    $jobs = iterator_to_array($xml->vacancies->vacancy, false);
    expect($jobs)->toHaveCount(1)
        ->and((string) $jobs[0]->url)->toBe('https://ozon.tech/vacancies/fdd52a53-63d5-4167-a1f1-e2cadc1a855f/')
        ->and((string) $jobs[0]->description)->toBe('Разработка сервисов');
    Http::assertSentCount(3);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/vacancy/138780339')
        || str_contains($request->url(), '/vacancy/06570187-117a-467f-8a0c-8160123325f5'));
});

test('ozon preserves the previous feed when hh status is unknown', function (mixed $hhId, bool $remove) {
    File::ensureDirectoryExists(dirname($this->ozonOutputPath));
    File::put($this->ozonOutputPath, '<source>previous</source>');
    $row = ozonRow('fdd52a53-63d5-4167-a1f1-e2cadc1a855f', 'internal_vacancy', 'Разработчик');
    if ($remove) {
        unset($row['hhId']);
    } else {
        $row['hhId'] = $hhId;
    }
    Http::fake(fn () => Http::response(ozonPage([$row], 1, 1, 1)));

    $this->artisan('ozon:collect-vacancies', ['--sleep-ms' => 0])->assertFailed();
    expect(File::get($this->ozonOutputPath))->toBe('<source>previous</source>');
    Http::assertSentCount(1);
})->with([
    'missing' => [null, true],
    'null' => [null, false],
    'negative' => [-1, false],
    'invalid string' => ['unknown', false],
]);

test('ozon rejects an external vacancy whose hh id is zero', function () {
    $row = ozonRow('1872eb11-cc54-4769-b488-a06e1c4ec67d', 'external_vacancy', 'Внешняя');
    Http::fake(fn () => Http::response(ozonPage([$row], 1, 1, 1)));

    $this->artisan('ozon:collect-vacancies', ['--sleep-ms' => 0])->assertFailed();
    expect(file_exists($this->ozonOutputPath))->toBeFalse();
});

test('ozon does not overwrite a previous feed when an allowed detail is empty', function () {
    File::ensureDirectoryExists(dirname($this->ozonOutputPath));
    File::put($this->ozonOutputPath, '<source>previous</source>');
    $row = ozonRow('fdd52a53-63d5-4167-a1f1-e2cadc1a855f', 'internal_vacancy', 'Разработчик');
    Http::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/v2/vacancy'
        ? Http::response(ozonPage([$row], 1, 1, 1))
        : Http::response(['name' => 'Разработчик', 'description' => '']));

    $this->artisan('ozon:collect-vacancies', ['--sleep-ms' => 0])->assertFailed();
    expect(File::get($this->ozonOutputPath))->toBe('<source>previous</source>');
});

test('ozon previews are stored outside the archive source folder', function () {
    $row = ozonRow('fdd52a53-63d5-4167-a1f1-e2cadc1a855f', 'internal_vacancy', 'Разработчик');
    Http::fake(fn (Request $request) => parse_url($request->url(), PHP_URL_PATH) === '/v2/vacancy'
        ? Http::response(ozonPage([$row], 1, 1, 1))
        : Http::response(['name' => 'Разработчик', 'city' => 'Москва', 'description' => '<p>Обязанности</p>']));

    $this->artisan('ozon:collect-vacancies', ['--detail-limit' => 1, '--sleep-ms' => 0])->assertSuccessful();
    expect(file_exists($this->ozonOutputPath))->toBeFalse()
        ->and(file_exists(storage_path('app/ozon-previews/ozon_vacancies'.today()->toDateString().'.xml')))->toBeTrue();
});

test('ozon skips an internal vacancy that returns 404', function () {
    $hidden = ozonRow('1872eb11-cc54-4769-b488-a06e1c4ec67d', 'internal_vacancy', 'Удалённая');
    $visible = ozonRow('fdd52a53-63d5-4167-a1f1-e2cadc1a855f', 'internal_vacancy', 'Доступная');
    Http::fake(function (Request $request) use ($hidden, $visible) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v2/vacancy') {
            return Http::response(ozonPage([$hidden, $visible], 1, 2, 2));
        }
        if ($path === '/v2/vacancy/1872eb11-cc54-4769-b488-a06e1c4ec67d') {
            return Http::response([], 404);
        }

        return Http::response(['name' => 'Доступная', 'city' => 'Москва', 'description' => 'Описание']);
    });

    $this->artisan('ozon:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file($this->ozonOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(1)
        ->and((string) $xml->vacancies->vacancy[0]->{'job-name'})->toBe('Доступная');
});
