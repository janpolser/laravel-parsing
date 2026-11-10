<?php

use Illuminate\Support\Facades\Artisan;

uses(Tests\TestCase::class);

test('legacy and current parsers are registered together', function () {
    $commands = Artisan::all();

    foreach ([
        'app:vacancy-yandex',
        'yandex:vacancy-yandex',
        'hirehi:parse-vacancies',
        'gossluzhba:collect-vacancies',
        'scraper:import-sites',
        'scraper:scrape-site',
        'scraper:build-feed',
    ] as $command) {
        expect($commands)->toHaveKey($command);
    }

    expect(config('universal_scraper.schedule.enabled'))->toBeFalse();
});
