<?php

use App\Support\FeedRequestPacer;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);

test('separate instances share timing for the same source', function () {
    $directory = sys_get_temp_dir().'/feed-pacing-'.bin2hex(random_bytes(8));
    $now = 1_000.0;
    $waited = [];
    $clock = static function () use (&$now): float { return $now; };
    $sleep = static function (int $delay) use (&$now, &$waited): void {
        $waited[] = $delay;
        $now += $delay / 1_000_000;
    };
    $random = static fn (): int => 4_500_000;

    try {
        (new FeedRequestPacer($directory, $clock, $sleep, $random))->wait('wildberries');
        (new FeedRequestPacer($directory, $clock, $sleep, $random))->wait('wildberries');
        (new FeedRequestPacer($directory, $clock, $sleep, $random))->wait('ozon');

        expect($waited)->toHaveCount(1)->and($waited[0])->toBeGreaterThanOrEqual(4_499_999);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('simultaneous processes share a locked source interval', function () {
    $directory = sys_get_temp_dir().'/feed-pacing-process-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($directory);
    $script = <<<'PHP'
require $argv[1];
$pacer = new App\Support\FeedRequestPacer($argv[2]);
$pacer->wait('wildberries');
echo sprintf('%.6F', microtime(true));
PHP;
    $first = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $directory]);
    $second = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $directory]);

    try {
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        expect($first->isSuccessful())->toBeTrue()->and($second->isSuccessful())->toBeTrue()
            ->and(abs((float) $first->getOutput() - (float) $second->getOutput()))->toBeGreaterThanOrEqual(2.9);
    } finally {
        File::deleteDirectory($directory);
    }
});

test('scheduled source commands share groups and active exceptional feeds remain excluded', function () {
    expect(FeedRequestPacer::groupForCommand('wb:collect-wb-vacancies'))->toBe('wildberries')
        ->and(FeedRequestPacer::groupForCommand('wb:generate-courier-vacancies'))->toBe('wildberries')
        ->and(FeedRequestPacer::groupForCommand('pyaterochka:vacancy-5ka'))->toBe('x5')
        ->and(FeedRequestPacer::groupForCommand('perekrestok:collect-vacancies'))->toBe('x5')
        ->and(FeedRequestPacer::groupForCommand('monetka:collect-vacancies'))->toBe('monetka')
        ->and(FeedRequestPacer::groupForCommand('hirehi:parse-vacancies'))->toBeNull()
        ->and(FeedRequestPacer::groupForCommand('gossluzhba:collect-vacancies'))->toBeNull()
        ->and(FeedRequestPacer::groupForCommand('scraper:scrape-site'))->toBeNull();

    foreach ([
        'magnit:collect-vacancy-magnit', 'yandex:vacancy-yandex', 'pyaterochka:vacancy-5ka',
        'perekrestok:collect-vacancies', 'krasnoeibeloe:collect-vacancies', 'rzhd:collect-vacancies',
        'wb:collect-wb-vacancies', 'wb:generate-courier-vacancies', 'wb:generate-driver-ce-vacancies',
        'wb:generate-driver-b-vacancies', 'wb:generate-storage-vacancies', 'ozon:collect-vacancies',
        'lenta:collect-vacancies', 'monetka:collect-vacancies', 'dixy:collect-vacancies',
        'vkusvill:collect-vacancies', 'kuper:collect-vacancies', 'oez:collect-vacancies',
    ] as $command) {
        expect(FeedRequestPacer::groupForCommand($command))->not->toBeNull();
    }
});

test('nested command scope restores its parent without affecting excluded commands', function () {
    $pacer = new FeedRequestPacer;
    $pacer->enter('ozon:collect-vacancies');
    expect($pacer->currentGroup())->toBe('ozon');
    $pacer->enter('hirehi:parse-vacancies');
    expect($pacer->currentGroup())->toBeNull();
    $pacer->leave();
    expect($pacer->currentGroup())->toBe('ozon');
    $pacer->leave();
    expect($pacer->currentGroup())->toBeNull();
});

test('HTTP middleware enforces a real delay between requests from a feed command', function () {
    $originalStorage = storage_path();
    $directory = sys_get_temp_dir().'/feed-pacing-http-'.bin2hex(random_bytes(8));
    app()->useStoragePath($directory);
    config(['feed_request_pacing.enabled' => true]);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['ok' => true])]);
    $input = new ArrayInput([]);
    $output = new NullOutput;

    try {
        Event::dispatch(new CommandStarting('ozon:collect-vacancies', $input, $output));
        Http::get('https://example.test/one');
        $start = microtime(true);
        Http::get('https://example.test/two');
        $elapsed = microtime(true) - $start;
        Event::dispatch(new CommandFinished('ozon:collect-vacancies', $input, $output, 0));

        Http::assertSentCount(2);
        expect($elapsed)->toBeGreaterThanOrEqual(2.9)->toBeLessThan(7.5);
    } finally {
        config(['feed_request_pacing.enabled' => false]);
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
});

test('HTTP middleware also delays a retry after a server error', function () {
    $originalStorage = storage_path();
    $directory = sys_get_temp_dir().'/feed-pacing-retry-'.bin2hex(random_bytes(8));
    app()->useStoragePath($directory);
    config(['feed_request_pacing.enabled' => true]);
    Http::preventStrayRequests();
    Http::fakeSequence()->push(['error' => 'temporary'], 503)->push(['ok' => true], 200);
    $input = new ArrayInput([]);
    $output = new NullOutput;

    try {
        Event::dispatch(new CommandStarting('lenta:collect-vacancies', $input, $output));
        $start = microtime(true);
        $response = Http::retry(2, 1, throw: false)->get('https://example.test/retry');
        $elapsed = microtime(true) - $start;
        Event::dispatch(new CommandFinished('lenta:collect-vacancies', $input, $output, 0));

        Http::assertSentCount(2);
        expect($response->ok())->toBeTrue()->and($elapsed)->toBeGreaterThanOrEqual(2.9);
    } finally {
        config(['feed_request_pacing.enabled' => false]);
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
});

test('an actual console feed command enables pacing for its HTTP requests', function () {
    $originalStorage = storage_path();
    $directory = sys_get_temp_dir().'/feed-pacing-command-'.bin2hex(random_bytes(8));
    app()->useStoragePath($directory);
    config(['feed_request_pacing.enabled' => true]);
    Http::preventStrayRequests();
    $times = [];
    Http::fake(function ($request) use (&$times) {
        $times[] = microtime(true);
        if (str_contains($request->url(), 'vacancies-json.php')) {
            return Http::response([
                'vacancies' => [['id' => '100', 'name' => 'Продавец', 'city' => 'Москва', 'type' => 'Магазины', 'schedule' => 'Сменный']],
                'pagenData' => ['element_count' => 1, 'page' => 1, 'pages_count' => 1, 'page_size' => 7],
            ]);
        }

        return Http::response('<html><h1 class="hr-vacancy__title">Продавец</h1><div class="hr-vacancy__content">Работа</div></html>');
    });

    try {
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $kernel->rerouteSymfonyCommandEvents();
        $kernel->setArtisan(null);
        Artisan::call('dixy:collect-vacancies', ['--max-pages' => 1, '--sleep-ms' => 0]);

        expect($times)->toHaveCount(2)->and($times[1] - $times[0])->toBeGreaterThanOrEqual(2.9);
    } finally {
        config(['feed_request_pacing.enabled' => false]);
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
});

test('redirects are also paced as separate HTTP requests', function () {
    $originalStorage = storage_path();
    $directory = sys_get_temp_dir().'/feed-pacing-redirect-'.bin2hex(random_bytes(8));
    app()->useStoragePath($directory);
    config(['feed_request_pacing.enabled' => true]);
    Http::preventStrayRequests();
    Http::fakeSequence()->push('', 302, ['Location' => 'https://example.test/target'])->push(['ok' => true], 200);
    $input = new ArrayInput([]);
    $output = new NullOutput;

    try {
        Event::dispatch(new CommandStarting('wb:collect-wb-vacancies', $input, $output));
        $start = microtime(true);
        $response = Http::get('https://example.test/start');
        $elapsed = microtime(true) - $start;
        Event::dispatch(new CommandFinished('wb:collect-wb-vacancies', $input, $output, 0));

        Http::assertSentCount(2);
        expect($response->ok())->toBeTrue()->and($elapsed)->toBeGreaterThanOrEqual(2.9);
    } finally {
        config(['feed_request_pacing.enabled' => false]);
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($directory);
    }
});
