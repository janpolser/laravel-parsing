<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->kbOriginalStoragePath = storage_path();
    $this->kbStoragePath = sys_get_temp_dir().'/kb-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->kbStoragePath);
    $this->kbOutputPath = storage_path('app/public/krasnoeibeloe/krasnoeibeloe_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->kbOriginalStoragePath);
    File::deleteDirectory($this->kbStoragePath);
});

function kbDiscovery(int $secondCount = 1): string
{
    $counts = ['r1' => [
        'c1' => ['any' => ['any' => ['any' => 1]]],
        'c2' => ['any' => ['any' => ['any' => $secondCount]]],
        'any' => ['any' => ['any' => ['any' => 1 + $secondCount]]],
    ]];
    $names = ['r1' => 'Алтайский край', 'c1' => 'Алейск', 'c2' => 'Барнаул'];

    return '<html><vacancy-filter :count-data="'.htmlspecialchars(json_encode($counts, JSON_UNESCAPED_UNICODE), ENT_QUOTES).'" :names-map="'.htmlspecialchars(json_encode($names, JSON_UNESCAPED_UNICODE), ENT_QUOTES).'"></vacancy-filter></html>';
}

function kbVacancy(string $city, string $token, int $id): array
{
    return ['id' => $id, 'link' => 'vacancy/?vacancy='.$token, 'city' => $city, 'region' => 'Алтайский край',
        'address' => 'ул. Тестовая, 1', 'lat' => 53.3, 'lon' => 83.6, 'metro' => [],
        'vacancy' => ['id' => $id + 100, 'position' => 'Продавец', 'department' => '',
            'salary' => ['from' => '45 000', 'to' => null], 'items' => [
                ['title' => 'Функционал', 'parts' => ['Работа с покупателями']],
                ['title' => 'Режим работы', 'parts' => ['2/2']],
            ]]];
}

function kbResponse(string $city): array
{
    $isFirst = $city === 'Алейск';

    return ['success' => true, 'data' => ['url' => $isFirst ? '/job/aleysk/' : '/job/barnaul/',
        'items' => [kbVacancy($city, $isFirst ? 'AA+/=' : 'BB==', $isFirst ? 1 : 2)]]];
}

test('kb collects every city and maps source data to a complete XML feed', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), '/job/chelyabinsk/')
        ? Http::response(kbDiscovery())
        : Http::response(kbResponse($request['city'])));

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();

    $xml = simplexml_load_file($this->kbOutputPath);
    $jobs = iterator_to_array($xml->vacancies->vacancy, false);
    expect($jobs)->toHaveCount(2)
        ->and((string) $jobs[0]->url)->toBe('https://krasnoeibeloe.ru/job/vacancy/?vacancy=AA%2B%2F%3D')
        ->and((string) $jobs[0]->description)->toContain('Работа с покупателями')
        ->and((string) $jobs[0]->salary)->toBe('от 45 000')
        ->and((string) $jobs[0]->addresses->address->location)->toBe('Алтайский край, Алейск, ул. Тестовая, 1')
        ->and((string) $jobs[0]->addresses->address->lat)->toBe('53.3')
        ->and((string) $jobs[0]->addresses->address->lng)->toBe('83.6');
    Http::assertSentCount(4);
});

test('kb does not replace a previous feed if the source changes during collection', function () {
    File::ensureDirectoryExists(dirname($this->kbOutputPath));
    File::put($this->kbOutputPath, '<source>previous</source>');
    $discoveryCount = 0;
    Http::fake(function (Request $request) use (&$discoveryCount) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            $discoveryCount++;

            return Http::response(kbDiscovery($discoveryCount === 1 ? 1 : 2));
        }

        return Http::response(kbResponse($request['city']));
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertFailed();
    expect(File::get($this->kbOutputPath))->toBe('<source>previous</source>');
});

test('kb reuses unchanged city checkpoints when source counts change', function () {
    $changed = false;
    Http::fake(function (Request $request) use (&$changed) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery($changed ? 2 : 1));
        }
        if ($request['city'] === 'Барнаул' && $changed) {
            $body = kbResponse('Барнаул');
            $body['data']['items'][] = kbVacancy('Барнаул', 'CC==', 3);

            return Http::response($body);
        }

        return Http::response(kbResponse($request['city']));
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();
    $changed = true;
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery(2));
        }
        $body = kbResponse($request['city']);
        if ($request['city'] === 'Барнаул') {
            $body['data']['items'][] = kbVacancy('Барнаул', 'CC==', 3);
        }

        return Http::response($body);
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file($this->kbOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(3);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/api/v2/vacancies/list/') && $request['city'] === 'Алейск');
});

test('kb preview stays outside public feed directory', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), '/job/chelyabinsk/')
        ? Http::response(kbDiscovery())
        : Http::response(kbResponse($request['city'])));

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();
    expect(file_exists($this->kbOutputPath))->toBeFalse()
        ->and(file_exists(storage_path('app/krasnoeibeloe-previews/krasnoeibeloe_vacancies'.today()->toDateString().'.xml')))->toBeTrue();
});

test('kb accepts source city pages without a city slug', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery());
        }
        $body = kbResponse($request['city']);
        $body['data']['url'] = '/job//';

        return Http::response($body);
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file(storage_path('app/krasnoeibeloe-previews/krasnoeibeloe_vacancies'.today()->toDateString().'.xml'));
    expect((string) $xml->vacancies->vacancy[0]->url)->toBe('https://krasnoeibeloe.ru/job/vacancy/?vacancy=AA%2B%2F%3D');
});

test('kb verifies a stale city counter against the source direction filters', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery());
        }
        $body = kbResponse('Алейск');
        $direction = $request['jobPlace'] ?? null;
        if ($direction === null || $direction === 'Офис') {
            $extra = kbVacancy('Алейск', 'extra==', 3);
            $body['data']['items'] = $direction === 'Офис' ? [$extra] : array_merge($body['data']['items'], [$extra]);
        } elseif ($direction === 'Распределительный центр') {
            $body['data']['items'] = [];
        }

        return Http::response($body);
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file(storage_path('app/krasnoeibeloe-previews/krasnoeibeloe_vacancies'.today()->toDateString().'.xml'));
    expect($xml->vacancies->vacancy)->toHaveCount(2);
    Http::assertSentCount(6);
});

test('kb accepts a stale positive counter only after an empty city is independently checked', function () {
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery());
        }
        $body = kbResponse($request['city']);
        if ($request['city'] === 'Алейск') {
            $body['data']['items'] = [];
        }

        return Http::response($body);
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file($this->kbOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(1)
        ->and((string) $xml->vacancies->vacancy[0]->addresses->address->location)->toContain('Барнаул');
});

test('kb refuses to publish if direction lists do not cover the city response', function () {
    File::ensureDirectoryExists(dirname($this->kbOutputPath));
    File::put($this->kbOutputPath, '<source>previous</source>');
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/job/chelyabinsk/')) {
            return Http::response(kbDiscovery());
        }
        $body = kbResponse($request['city']);
        if ($request['city'] === 'Алейск' && !isset($request['jobPlace'])) {
            $body['data']['items'][] = kbVacancy('Алейск', 'extra==', 3);
        }
        if (isset($request['jobPlace'])) {
            $body['data']['items'] = [];
        }

        return Http::response($body);
    });

    $this->artisan('krasnoeibeloe:collect-vacancies', ['--sleep-ms' => 0])->assertFailed();
    expect(File::get($this->kbOutputPath))->toBe('<source>previous</source>');
});

test('kb normalizes legacy cached links without changing their vacancy identifier', function () {
    Http::fake(fn (Request $request) => str_contains($request->url(), '/job/chelyabinsk/')
        ? Http::response(kbDiscovery())
        : Http::response(kbResponse($request['city'])));
    $this->artisan('krasnoeibeloe:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();

    $path = storage_path('app/krasnoeibeloe-cache/'.hash('sha256', "Алтайский край\0Алейск").'.json');
    $cached = json_decode(File::get($path), true);
    $cached['entities'][0]['url'] = 'https://krasnoeibeloe.ru/job/aleysk/vacancy/?vacancy=AA+/=';
    $cached['entities'][0]['mobile_url'] = $cached['entities'][0]['url'];
    File::put($path, json_encode($cached, JSON_UNESCAPED_UNICODE));

    Http::fake(fn () => Http::response(kbDiscovery()));
    $this->artisan('krasnoeibeloe:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file(storage_path('app/krasnoeibeloe-previews/krasnoeibeloe_vacancies'.today()->toDateString().'.xml'));
    expect((string) $xml->vacancies->vacancy[0]->url)->toBe('https://krasnoeibeloe.ru/job/vacancy/?vacancy=AA%2B%2F%3D');
    Http::assertSentCount(1);
});
