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
    ] as $command) {
        expect($commands)->toHaveKey($command);
    }

});
