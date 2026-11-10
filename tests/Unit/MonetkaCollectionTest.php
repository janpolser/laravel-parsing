<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->monetkaOriginalStoragePath = storage_path();
    $this->monetkaStoragePath = sys_get_temp_dir().'/monetka-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->monetkaStoragePath);
});

function monetkaListHtml(): string
{
    return <<<'HTML'
    <html><body>
      <div class="job-card" data-href="/vakansii/100-pekar/"><div class="job-title">Пекарь</div><div class="job-meta">Екатеринбург • полная</div><div class="job-desc">Зарплата: от 40 000 руб.</div><a class="apply-button job-card-button" href="/vakansii/100-pekar/">Подробнее</a></div>
      <div class="job-card" data-href="/vakansii/200-pekar/"><div class="job-title">Пекарь 2</div><div class="job-meta">Москва • полная</div><div class="job-desc">Зарплата: от 50 000 руб.</div><a class="apply-button job-card-button" href="/vakansii/200-pekar/">Подробнее</a></div>
    </body></html>
    HTML;
}

function monetkaDetailHtml(string $id, string $title, string $address): string
{
    return <<<HTML
    <html><body>
      <h1 class="v-hero__title">{$title}</h1>
      <div>Вакансия №{$id}</div>
      <section class="v-facts"><article class="fact"><div class="fact__title">Заработная плата</div><div class="fact__value">от 40 000 ₽</div></article><article class="fact"><div class="fact__title">Тип занятости</div><div class="fact__value">полная</div></article><article class="fact"><div class="fact__title">Адрес</div><div class="fact__value">{$address}</div></article></section>
      <article class="v-content"><p>Монетка – Лидер рынка. Официальное трудоустройство.</p><ul><li>Работа в торговом зале</li><li>Скидки сотрудникам</li></ul><div>← Ко всем вакансиям</div></article>
    </body></html>
    HTML;
}

afterEach(function () {
    app()->useStoragePath($this->monetkaOriginalStoragePath);
    File::deleteDirectory($this->monetkaStoragePath);
});

test('monetka collects unique bakery vacancy links and publishes feed', function () {
    Http::fake(function (Request $request) {
        if ($request->url() === 'https://career.monetka.ru/search/') {
            return Http::response(monetkaListHtml());
        }
        if (str_ends_with($request->url(), '/100-pekar/')) {
            return Http::response(monetkaDetailHtml('100', 'Пекарь', 'Екатеринбург, Тестовая, 1'));
        }

        return Http::response(monetkaDetailHtml('200', 'Пекарь 2', 'Москва, Тестовая, 2'));
    });

    $this->artisan('monetka:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();

    $path = storage_path('app/public/monetka/monetka_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    $xml = simplexml_load_file($path);
    expect($xml->vacancies->vacancy)->toHaveCount(2);
    expect((string) $xml->vacancies->vacancy[0]->{'job-name'})->toBe('Пекарь')
        ->and((string) $xml->vacancies->vacancy[0]->addresses->address->location)->toBe('Екатеринбург, Тестовая, 1')
        ->and((string) $xml->vacancies->vacancy[0]->description)->toContain('Работа в торговом зале')
        ->and((string) $xml->vacancies->vacancy[0]->campaign)->toBe('Монетка');
    Http::assertSentCount(3);
});

test('monetka keeps previous feed when list request fails', function () {
    $path = storage_path('app/public/monetka/monetka_vacancies'.today('Europe/Moscow')->toDateString().'.xml');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, '<source>previous</source>');
    Http::fake(fn () => Http::response('unavailable', 503));

    $this->artisan('monetka:collect-vacancies')->assertFailed();

    expect(File::get($path))->toBe('<source>previous</source>');
});

test('monetka preview stays outside public feed directory', function () {
    Http::fake(fn (Request $request) => $request->url() === 'https://career.monetka.ru/search/'
        ? Http::response(monetkaListHtml())
        : Http::response(monetkaDetailHtml('100', 'Пекарь', 'Екатеринбург, Тестовая, 1')));

    $this->artisan('monetka:collect-vacancies', ['--max-vacancies' => 1, '--sleep-ms' => 0])->assertSuccessful();

    expect(file_exists(storage_path('app/public/monetka/monetka_vacancies'.today('Europe/Moscow')->toDateString().'.xml')))->toBeFalse()
        ->and(file_exists(storage_path('app/monetka-previews/monetka_vacancies'.today('Europe/Moscow')->toDateString().'.xml')))->toBeTrue();
});
