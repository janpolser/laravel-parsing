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

test('a protection response from configuration stops before listing requests', function () {
    Http::fake(fn () => Http::response([], 403));

    $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
    Http::assertSentCount(1);
    expect(File::exists(storage_path('app/private/gossluzhba.disabled')))->toBeTrue()
        ->and(File::get($this->output))->toBe('<previous/>');
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

test('a complete multi-page feed contains every vacancy', function () {
    Http::fake(function (Request $request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $id = (string) $request['page'];

            return Http::response([
                'vacancies' => [['id' => $id, 'caption' => 'Специалист '.$id, 'organizationName' => 'Организация']],
                'pageCount' => 2,
                'total' => 2,
            ]);
        }

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000,
        '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(2);
    Http::assertSentCount(5);
});

test('a single list pass is used before detail requests', function () {
    $listCalls = 0;
    $requests = [];
    Http::fake(function (Request $request) use (&$listCalls, &$requests) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $requests[] = $path;
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $listCalls++;
            $items = [['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация']];
            if ($listCalls > 1) {
                $items[] = ['id' => '2', 'caption' => 'Два', 'organizationName' => 'Организация'];
            }

            return Http::response(['vacancies' => $items, 'pageCount' => 1, 'total' => count($items)]);
        }

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(1)
        ->and($listCalls)->toBe(1)
        ->and($requests[1])->toContain('get-vacancies')
        ->and($requests[2])->not->toContain('get-vacancies')
        ->and(json_decode(File::get(storage_path('app/gossluzhba/state/list-snapshot.json')), true)['count'])->toBe(1);
});

test('a small total change between pages is accepted without rescanning', function () {
    $listCalls = 0;
    $detailListCounts = [];
    Http::fake(function (Request $request) use (&$listCalls, &$detailListCounts) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $listCalls++;
            $page = (int) $request['page'];
            $items = $page === 1
                ? [['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация'], ['id' => '2', 'caption' => 'Два', 'organizationName' => 'Организация']]
                : [['id' => '3', 'caption' => 'Три', 'organizationName' => 'Организация']];

            return Http::response(['vacancies' => $items, 'pageCount' => 2, 'total' => $listCalls === 1 ? 2 : 3]);
        }
        $detailListCounts[] = $listCalls;

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect($detailListCounts)->toBe([2, 2, 2])
        ->and(substr_count(File::get($this->output), '<vacancy>'))->toBe(3);
});

test('a vacancy added after the list pass is left to the next run', function () {
    $detailCalls = [];
    Http::fake(function (Request $request) use (&$detailCalls) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $items = [['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация']];
            if ($detailCalls !== []) {
                $items[] = ['id' => '2', 'caption' => 'Два', 'organizationName' => 'Организация'];
            }

            return Http::response(['vacancies' => $items, 'pageCount' => 1, 'total' => count($items)]);
        }
        $detailCalls[] = basename($path);

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(1)
        ->and($detailCalls)->toBe(['1']);
});

test('a disappeared vacancy with 404 is excluded without rescanning', function () {
    $missingSeen = false;
    Http::fake(function (Request $request) use (&$missingSeen) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $items = [['id' => '2', 'caption' => 'Два', 'organizationName' => 'Организация']];
            if (! $missingSeen) {
                array_unshift($items, ['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация']);
            }

            return Http::response(['vacancies' => $items, 'pageCount' => 1, 'total' => count($items)]);
        }
        if (basename($path) === '1') {
            $missingSeen = true;

            return Http::response([], 404);
        }

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(1)
        ->and(File::get($this->output))->toContain('/vacancy/2');
});

test('one scan does not make requests to check subsequent changes', function () {
    $listCalls = 0;
    Http::fake(function (Request $request) use (&$listCalls) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            $listCalls++;
            $id = $listCalls <= 2 ? '1' : (string) $listCalls;

            return Http::response(['vacancies' => [['id' => $id, 'caption' => 'Специалист', 'organizationName' => 'Организация']], 'pageCount' => 1, 'total' => 1]);
        }

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertSuccessful();

    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(1)->and($listCalls)->toBe(1);
});

test('repeated duplicate pages never publish a partial snapshot', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), 'private-get')
        ? Http::response([])
        : Http::response([
            'vacancies' => [['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация']],
            'pageCount' => 2,
            'total' => 2,
        ]));

    $this->artisan('gossluzhba:collect-vacancies', [
        '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000,
    ])->assertFailed();

    expect(File::get($this->output))->toBe('<previous/>');
    Http::assertSentCount(3);
});

test('a second collector cannot start while the source is locked', function () {
    $path = storage_path('app/gossluzhba/state/collect.lock');
    File::ensureDirectoryExists(dirname($path));
    $lock = fopen($path, 'c');
    flock($lock, LOCK_EX);
    Http::fake();

    try {
        $this->artisan('gossluzhba:collect-vacancies')->assertFailed();
        Http::assertNothingSent();
        expect(File::get($this->output))->toBe('<previous/>');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});

test('a later run reuses a cached successful detail after a vacancy was skipped', function () {
    $failSecond = true;
    $detailCalls = [];
    Http::fake(function (Request $request) use (&$failSecond, &$detailCalls) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_contains($path, 'private-get')) {
            return Http::response([]);
        }
        if (str_contains($path, 'get-vacancies')) {
            return Http::response([
                'vacancies' => [
                    ['id' => '1', 'caption' => 'Один', 'organizationName' => 'Организация'],
                    ['id' => '2', 'caption' => 'Два', 'organizationName' => 'Организация'],
                ],
                'pageCount' => 1,
                'total' => 2,
            ]);
        }
        $id = basename($path);
        $detailCalls[] = $id;
        if ($id === '2' && $failSecond) {
            return Http::response([], 404);
        }

        return Http::response(['vacancy' => ['caption' => 'Специалист', 'organizationName' => 'Организация']]);
    });
    $options = ['--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000];

    $this->artisan('gossluzhba:collect-vacancies', $options)->assertSuccessful();
    expect(substr_count(File::get($this->output), '<vacancy>'))->toBe(1)
        ->and(File::exists(storage_path('app/gossluzhba/cache/details/1.json')))->toBeTrue();
    $failSecond = false;
    $this->artisan('gossluzhba:collect-vacancies', $options)->assertSuccessful();

    expect($detailCalls)->toBe(['1', '2', '2'])
        ->and(substr_count(File::get($this->output), '<vacancy>'))->toBe(2);
});
