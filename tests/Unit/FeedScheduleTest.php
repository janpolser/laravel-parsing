<?php

use Illuminate\Console\Scheduling\Schedule;

uses(Tests\TestCase::class);

test('сборы распределены по неделе и публикация не запускается по отдельному расписанию', function () {
    $events = collect(app(Schedule::class)->events());
    $expected = [
        'pyaterochka:vacancy-5ka' => '0 7 * * 1',
        'perekrestok:collect-vacancies' => '0 7 * * 1',
        'magnit:collect-vacancy-magnit' => '0 7 * * 2',
        'dixy:collect-vacancies' => '0 7 * * 2',
        'hirehi:parse-vacancies' => '0 4 * * 3',
        'yandex:vacancy-yandex' => '0 7 * * 3',
        'rzhd:collect-vacancies' => '0 7 * * 4',
        'lenta:collect-vacancies' => '0 7 * * 4',
        'monetka:collect-vacancies' => '0 7 * * 5',
        'monetka:collect-vacancies --section=pekarni' => '0 7 * * 5',
        'gossluzhba:collect-vacancies --details-limit=0 --sleep-ms=1500 --refresh-days=30 --without-tls-verify' => '0 7 * * 5',
        'wb:collect-wb-vacancies' => '0 7 * * 6',
        'ozon:collect-vacancies' => '0 7 * * 6',
        'krasnoeibeloe:collect-vacancies' => '0 7 * * 0',
        'vkusvill:collect-vacancies' => '0 7 * * 0',
        'vkusvill:collect-vacancies --section=all' => '0 7 * * 0',
        'kuper:collect-vacancies' => '0 7 * * 0',
    ];
    foreach ($expected as $command => $cron) {
        $matching = $events->filter(fn ($event) => str_contains($event->command ?? '', $command));
        if (!str_contains($command, '--section=')) {
            $matching = $matching->filter(fn ($event) => !str_contains($event->command, '--section='));
        }
        expect($matching)->toHaveCount(1)->and($matching->first()->expression)->toBe($cron)
            ->and($matching->first()->timezone)->toBe('Europe/Moscow');
    }
    expect($events->filter(fn ($event) => str_contains($event->command ?? '', 'xml:tar')))->toBeEmpty();
    expect($events->filter(fn ($event) => str_contains($event->command ?? '', 'wb:generate-')))->toBeEmpty();
    expect(trim(file_get_contents(base_path('docker/gossluzhba-tunnel/crontab'))))->toStartWith('0 7 * * 5 ');
});
