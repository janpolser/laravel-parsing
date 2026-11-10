<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->originalStorage = storage_path();
    $this->testStorage = sys_get_temp_dir().'/gossluzhba-test-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->testStorage);
    $this->output = storage_path('app/public/gossluzhba/GossluzhbaVacancies'.now('Europe/Moscow')->toDateString().'.xml');
    File::ensureDirectoryExists(dirname($this->output));
    File::put($this->output, '<previous/>');
});

afterEach(function () {
    app()->useStoragePath($this->originalStorage);
    File::deleteDirectory($this->testStorage);
});

test('an empty intermediate page does not replace the previous feed', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'private-get')
        ? Http::response([])
        : Http::response(['vacancies' => [], 'pageCount' => 2, 'total' => 1]));

    $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
    expect(File::get($this->output))->toBe('<previous/>');
    Http::assertSentCount(2);
});

test('a protection response is not retried', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'private-get')
        ? Http::response([])
        : Http::response([], 403));

    $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
    expect(File::get($this->output))->toBe('<previous/>');
    Http::assertSentCount(2);
    expect(File::exists(storage_path('app/private/gossluzhba.disabled')))->toBeTrue();
    $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
    Http::assertSentCount(2);
});

test('a feed without vacancies does not replace the previous feed', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'private-get')
        ? Http::response([])
        : Http::response(['vacancies' => [], 'pageCount' => 1, 'total' => 0]));

    $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
    expect(File::get($this->output))->toBe('<previous/>');
});

test('the delay applies between configuration and list requests', function () {
    $times = [];
    Http::fake(function (Request $request) use (&$times) {
        $times[] = microtime(true);

        return str_contains($request->url(), 'private-get')
            ? Http::response([])
            : Http::response(['vacancies' => [], 'pageCount' => 1, 'total' => 0]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000,
        '--request-delay-max-ms' => 2000,
    ])->assertFailed();

    expect($times)->toHaveCount(2)->and($times[1] - $times[0])->toBeGreaterThanOrEqual(1.9);
});
