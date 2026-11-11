<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->cron('0 7 * * 2')
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 3')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'yandex']);
        if ($exitCode !== 0) {
            Log::error('Не удалось создать архив фида yandex', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->cron('0 7 * * 1')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => '5ka']);
        if ($exitCode !== 0) {
            Log::error('Не удалось создать архив фида 5ka', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Перекрёсток
Schedule::command('perekrestok:collect-vacancies')
    ->cron('0 7 * * 1')
    ->timezone('Europe/Moscow')
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
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 4')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'rzhd']);
        if ($exitCode !== 0) {
            Log::error('Не удалось создать архив фида rzhd', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->cron('0 7 * * 6')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        foreach (['wb:generate-courier-vacancies', 'wb:generate-driver-ce-vacancies', 'wb:generate-driver-b-vacancies', 'wb:generate-storage-vacancies'] as $command) {
            if (Artisan::call($command) !== 0) {
                Log::error('Не удалось подготовить дополнительный фид WB', ['command' => $command, 'output' => Artisan::output()]);

                return;
            }
        }
        if (Artisan::call('xml:tar', ['source' => 'wb']) !== 0) {
            Log::error('Не удалось создать архив фида WB', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Ozon
Schedule::command('ozon:collect-vacancies')
    ->cron('0 7 * * 6')
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 4')
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 5')
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 2')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'dixy']);
        if ($exitCode !== 0) {
            Log::error('Dixy feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

Schedule::command('monetka:collect-vacancies --section=pekarni')
    ->cron('0 7 * * 5')
    ->timezone('Europe/Moscow')
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
    ->timezone('Europe/Moscow')
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
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'vkusvill-all']);
        if ($exitCode !== 0) {
            Log::error('VkusVill all-jobs feed archive generation failed', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// Купер
Schedule::command('kuper:collect-vacancies')
    ->cron('0 7 * * 0')
    ->timezone('Europe/Moscow')
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'kuper']);
        if ($exitCode !== 0) {
            Log::error('Не удалось создать архив фида kuper', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();

// HireHi
Schedule::command('hirehi:parse-vacancies')
    ->cron('0 4 * * 3')
    ->timezone('Europe/Moscow')
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
    ->cron('0 7 * * 5')
    ->timezone('Europe/Moscow')
    ->when(fn () => !filter_var(env('GOSSLUZHBA_EXTERNAL_RUNNER', false), FILTER_VALIDATE_BOOLEAN))
    ->appendOutputTo('/proc/1/fd/1')
    ->onSuccess(function () {
        $exitCode = Artisan::call('xml:tar', ['source' => 'gossluzhba']);
        if ($exitCode !== 0) {
            Log::error('Не удалось создать архив фида gossluzhba', ['output' => Artisan::output()]);
        }
    })
    ->withoutOverlapping();
