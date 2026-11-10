<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'magnit']);
        if ($exitCode !== 0) {
            Log::error('Magnit feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Яндекс
Schedule::command('yandex:vacancy-yandex')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Перекрёсток
Schedule::command('perekrestok:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'perekrestok']);
        if ($exitCode !== 0) {
            Log::error('Perekrestok feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Красное&Белое
Schedule::command('krasnoeibeloe:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'krasnoeibeloe']);
        if ($exitCode !== 0) {
            Log::error('Krasnoe&Beloe feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->runInBackground()
    ->withoutOverlapping();

// РЖД
Schedule::command('rzhd:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Ozon
Schedule::command('ozon:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'ozon']);
        if ($exitCode !== 0) {
            Log::error('Ozon feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Лента
Schedule::command('lenta:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'lenta']);
        if ($exitCode !== 0) {
            Log::error('Lenta feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Монетка
Schedule::command('monetka:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'monetka']);
        if ($exitCode !== 0) {
            Log::error('Monetka feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// ДИКСИ
Schedule::command('dixy:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'dixy']);
        if ($exitCode !== 0) {
            Log::error('Dixy feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

Schedule::command('monetka:collect-vacancies --section=pekarni')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'monetka-pekarni']);
        if ($exitCode !== 0) {
            Log::error('Monetka bakery feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// ВкусВилл
Schedule::command('vkusvill:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'vkusvill']);
        if ($exitCode !== 0) {
            Log::error('VkusVill feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

Schedule::command('vkusvill:collect-vacancies --section=all')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'vkusvill-all']);
        if ($exitCode !== 0) {
            Log::error('VkusVill all-jobs feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

Schedule::command('wb:generate-courier-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-ce-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-b-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-storage-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Купер
Schedule::command('kuper:collect-vacancies')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// HireHi
Schedule::command('hirehi:parse-vacancies')
    ->cron('0 4 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'hirehi']);
        if ($exitCode !== 0) {
            Log::error('HireHi feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->runInBackground()
    ->withoutOverlapping();

// Gossluzhba
Schedule::command('gossluzhba:collect-vacancies --details-limit=0 --sleep-ms=1500 --refresh-days=30 --without-tls-verify')
    ->cron('0 7 * * 0')
    ->when(fn () => !filter_var(env('GOSSLUZHBA_EXTERNAL_RUNNER', false), FILTER_VALIDATE_BOOLEAN))
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Создание архивов
Schedule::command('xml:tar gossluzhba')
    ->cron('0 10 * * 0')
    ->when(fn () => !filter_var(env('GOSSLUZHBA_EXTERNAL_RUNNER', false), FILTER_VALIDATE_BOOLEAN))
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('xml:tar --exclude=hirehi --exclude=ozon --exclude=perekrestok --exclude=krasnoeibeloe --exclude=gossluzhba')
    ->cron('0 7 * * 0')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

if (config('universal_scraper.schedule.enabled')) {
    Schedule::command('scraper:schedule-due-sites --limit=' . config('universal_scraper.schedule.due_sites_limit', 100))
        ->hourly()
        ->appendOutputTo('/proc/1/fd/1')
        ->withoutOverlapping();

    Schedule::command('scraper:build-feed')
        ->everyTenMinutes()
        ->appendOutputTo('/proc/1/fd/1')
        ->withoutOverlapping();
}
