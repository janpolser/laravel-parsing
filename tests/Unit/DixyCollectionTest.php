<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->dixyOriginalStoragePath = storage_path();
    $this->dixyStoragePath = sys_get_temp_dir().'/dixy-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->dixyStoragePath);
});

afterEach(function () {
    app()->useStoragePath($this->dixyOriginalStoragePath);
    File::deleteDirectory($this->dixyStoragePath);
});

function dixyPage(array $items, int $page, int $pages = 2, int $total = 3): array
{
    return ['vacancies' => $items, 'pagenData' => [
        'element_count' => $total, 'page' => $page, 'pages_count' => $pages, 'page_size' => 7,
    ]];
}

function dixyRow(string $id, string $name, string $city): array
{
    return ['id' => $id, 'name' => $name, 'city' => $city, 'type' => 'Магазины', 'schedule' => 'Сменный'];
}

function dixyDetailHtml(string $title): string
{
    return <<<HTML
    <html><body>
      <h1 class="hr-vacancy__title">{$title}</h1>
      <div class="hr-vacancy__content"><div class="hr-vacancy__chips"><span>Магазины</span></div>
        <div class="hr-vacancy__list"><h2>Что включает должность</h2><ul><li>Обслуживание покупателей</li><li>Выкладка товара</li></ul><h2>Мы гарантируем условия</h2><ul><li>Официальное трудоустройство</li></ul></div>
      </div>
    </body></html>
    HTML;
}

test('dixy collects all API pages and publishes detail feed', function () {
    $rows = [dixyRow('100', 'Продавец', 'Москва'), dixyRow('101', 'Кассир', 'Тула'), dixyRow('102', 'Директор магазина', 'Брянск')];
    Http::fake(function (Request $request) use ($rows) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/ajax/vacancies-json.php') {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return (int) ($query['page'] ?? 0) === 1
                ? Http::response(dixyPage([$rows[0], $rows[1]], 1))
                : Http::response(dixyPage([$rows[2]], 2));
        }

        $id = basename(trim((string) $path, '/'));

        return Http::response(dixyDetailHtml($rows[(int) $id - 100]['name']));
    });

    $this->artisan('dixy:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();

    $path = storage_path('app/public/dixy/dixy_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    $xml = simplexml_load_file($path);
    expect($xml->vacancies->vacancy)->toHaveCount(3)
        ->and((string) $xml->vacancies->vacancy[0]->{'job-name'})->toBe('Продавец')
        ->and((string) $xml->vacancies->vacancy[0]->addresses->address->location)->toBe('Москва')
        ->and((string) $xml->vacancies->vacancy[0]->description)->toContain('Обслуживание покупателей');
    Http::assertSentCount(5);
});

test('dixy keeps previous feed when API pagination is inconsistent', function () {
    $path = storage_path('app/public/dixy/dixy_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<source>previous</source>');
    Http::fake(fn () => Http::response(dixyPage([dixyRow('100', 'Продавец', 'Москва')], 1, 2, 3)));

    $this->artisan('dixy:collect-vacancies')->assertFailed();

    expect(File::get($path))->toBe('<source>previous</source>');
});

test('dixy preview stays outside public feed directory', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'vacancies-json.php')
        ? Http::response(dixyPage([dixyRow('100', 'Продавец', 'Москва')], 1, 1, 1))
        : Http::response(dixyDetailHtml('Продавец')));

    $this->artisan('dixy:collect-vacancies', ['--max-pages' => 1, '--sleep-ms' => 0])->assertSuccessful();

    expect(file_exists(storage_path('app/public/dixy/dixy_vacancies'.today('Europe/Moscow')->toDateString().'.xml')))->toBeFalse()
        ->and(file_exists(storage_path('app/dixy-previews/dixy_vacancies'.today('Europe/Moscow')->toDateString().'.xml')))->toBeTrue();
});
