<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->magnitWorkingDirectory = sys_get_temp_dir().'/magnit-feed-'.bin2hex(random_bytes(8));
    mkdir($this->magnitWorkingDirectory);
    $this->magnitPreviousDirectory = getcwd();
    chdir($this->magnitWorkingDirectory);
});

afterEach(function () {
    chdir($this->magnitPreviousDirectory);
    File::deleteDirectory($this->magnitWorkingDirectory);
});

test('magnit downloads compressed responses and publishes a complete feed', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/locality?')) {
            return Http::response([
                'success' => true,
                'meta' => ['total' => 1, 'has_more_pages' => false],
                'results' => [['id' => 3047, 'name' => 'Москва', 'slug' => 'moskva']],
            ]);
        }

        if (str_contains($request->url(), '/vacancy?')) {
            return Http::response([
                'success' => true,
                'meta' => ['total' => 1, 'current_page' => 1, 'has_more_pages' => false],
                'results' => [['id' => 'vacancy-1', 'name' => 'Продавец', 'active' => true, 'address' => 'Москва']],
            ]);
        }

        return Http::response(['results' => ['id' => 'vacancy-1', 'address' => 'Москва']]);
    });

    $this->artisan('magnit:collect-vacancy-magnit')->assertSuccessful();

    $files = glob('storage/app/public/magnit/*.xml');
    expect($files)->toHaveCount(1);
    expect(substr_count(file_get_contents($files[0]), '<vacancy>'))->toBe(1);
    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => $request->hasHeader('Accept-Encoding', 'gzip'));
    expect(Http::recorded()->every(fn ($pair) => $pair[0]->hasHeader('Accept-Encoding', 'gzip')))->toBeTrue();
});

test('magnit does not publish a partial feed when a vacancy page is invalid', function () {
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/locality?')) {
            return Http::response([
                'success' => true,
                'meta' => ['total' => 1, 'has_more_pages' => false],
                'results' => [['id' => 3047, 'name' => 'Москва', 'slug' => 'moskva']],
            ]);
        }

        return Http::response([
            'success' => false,
            'meta' => ['current_page' => 1, 'has_more_pages' => false],
            'results' => [],
        ]);
    });

    expect(fn () => $this->artisan('magnit:collect-vacancy-magnit'))
        ->toThrow(RuntimeException::class, 'Неполная страница вакансий');

    expect(glob('storage/app/public/magnit/*.xml'))->toBe([]);
    expect(glob('storage/app/public/magnit/*.tmp'))->toBe([]);
});

test('magnit rejects a truncated locality catalogue', function () {
    Http::fake([
        'https://rabota.magnit.ru/api/v1/locality*' => Http::response([
            'success' => true,
            'meta' => ['total' => 2, 'has_more_pages' => false],
            'results' => [['id' => 3047, 'name' => 'Москва', 'slug' => 'moskva']],
        ]),
    ]);

    $this->artisan('magnit:collect-vacancy-magnit')->assertFailed();

    expect(glob('storage/app/public/magnit/*.xml'))->toBe([]);
    Http::assertSentCount(1);
});
