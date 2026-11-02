<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->dailyAt('07:00')
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
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// РЖД
Schedule::command('rzhd:collect-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-courier-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-ce-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-b-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-storage-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Купер
Schedule::command('kuper:collect-vacancies')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// HireHi
Schedule::command('hirehi:parse-vacancies')
    ->dailyAt('04:00')
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
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Создание архивов
Schedule::command('xml:tar gossluzhba')
    ->dailyAt('10:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('xml:tar --exclude=hirehi')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();
