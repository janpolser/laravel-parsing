<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->vkusvillOriginalStoragePath = storage_path();
    $this->vkusvillStoragePath = sys_get_temp_dir().'/vkusvill-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->vkusvillStoragePath);
});

afterEach(function () {
    app()->useStoragePath($this->vkusvillOriginalStoragePath);
    File::deleteDirectory($this->vkusvillStoragePath);
});

function vkusvillPageHtml(): string
{
    return '<html><body><input name="sessid" value="session-1"></body></html>';
}

function vkusvillApiHtml(string $url, string $title, string $city = 'Москва'): string
{
    return <<<HTML
    <a href="{$url}" class="VV24_VakansiiOfis_Role__Item">
      <div class="VV24_VakansiiOfis_Role__Item_Name">{$title}</div>
      <div class="VV24_VakansiiOfis_Role__Item_Props"><div class="_team">Аналитики</div><div class="_city"><div>{$city}</div></div></div>
    </a>
    HTML;
}

function vkusvillDetailHtml(string $title, string $address): string
{
    return <<<HTML
    <html><body><div class="VV21_VacancyDetail">
      <h1 class="VV21_VacancyDetail__Title">{$title}</h1>
      <div>ВкусВилл — бренд натуральных продуктов.</div>
      <div>Анализировать данные и улучшать процессы.</div>
      <div>Адрес</div><div>{$address}</div>
    </div></body></html>
    HTML;
}

test('vkusvill paginates API and publishes all detail pages', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://vkusvill.ru/job/office/') {
            return Http::response(vkusvillPageHtml());
        }
        if (str_contains($request->url(), 'hh_list_filter.php')) {
            $page = (int) $request['nextPage'];
            $html = $page === 1
                ? vkusvillApiHtml('/job/vacancys/one_1.html', 'Аналитик')
                : vkusvillApiHtml('/job/vacancys/two_2.html', 'Аналитик 2');

            return Http::response(['success' => 'Y', 'data' => [
                'vacancies' => $html, 'nav' => '<button data-page="2">2</button>', 'cities' => [], 'teams' => [],
            ]]);
        }
        if (str_ends_with($request->url(), '/one_1.html')) {
            return Http::response(vkusvillDetailHtml('Аналитик', 'Москва, Тестовая, 1'));
        }

        return Http::response(vkusvillDetailHtml('Аналитик 2', 'Москва, Тестовая, 2'));
    });

    $this->artisan('vkusvill:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();

    $path = storage_path('app/public/vkusvill/vkusvill_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    $xml = simplexml_load_file($path);
    expect($xml->vacancies->vacancy)->toHaveCount(2)
        ->and((string) $xml->vacancies->vacancy[0]->{'job-name'})->toBe('Аналитик')
        ->and((string) $xml->vacancies->vacancy[0]->description)->toContain('Анализировать данные')
        ->and((string) $xml->vacancies->vacancy[0]->addresses->address->location)->toBe('Москва, Тестовая, 1');
    Http::assertSentCount(5);
});

test('vkusvill keeps previous feed when API fails', function () {
    $path = storage_path('app/public/vkusvill/vkusvill_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<source>previous</source>');
    Http::fake(fn () => Http::response('unavailable', 503));

    $this->artisan('vkusvill:collect-vacancies')->assertFailed();

    expect(File::get($path))->toBe('<source>previous</source>');
});

test('vkusvill all mode multiplies vacancies by available cities', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://vkusvill.ru/job/') {
            return Http::response(vkusvillPageHtml());
        }
        if (str_contains($request->url(), 'hh_list_filter.php')) {
            return Http::response(['success' => 'Y', 'data' => [
                'vacancies' => vkusvillApiHtml('/job/vacancys/one_1.html', 'Аналитик'),
                'nav' => '',
                'cities' => [['ID' => '1', 'NAME' => 'Москва'], ['ID' => '2', 'NAME' => 'Казань']],
                'teams' => [],
            ]]);
        }

        return Http::response(vkusvillDetailHtml('Аналитик', 'Москва, Тестовая, 1'));
    });

    $this->artisan('vkusvill:collect-vacancies', ['--section' => 'all', '--sleep-ms' => 0])->assertSuccessful();

    $path = storage_path('app/public/vkusvill-all/vkusvill_all_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    $xml = simplexml_load_file($path);
    expect($xml->vacancies->vacancy)->toHaveCount(2)
        ->and((string) $xml->vacancies->vacancy[0]->addresses->address->location)->toBe('Москва')
        ->and((string) $xml->vacancies->vacancy[1]->addresses->address->location)->toBe('Казань')
        ->and((string) $xml->vacancies->vacancy[1]->description)->toContain('Город трудоустройства: Казань');
});
