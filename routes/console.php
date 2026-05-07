<?php

use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Яндекс
Schedule::command('yandex:vacancy-yandex')
    ->dailyAt('07:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('yandex:prepare-data-to-format')
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


// Создание архивов
Schedule::command('xml:tar')
    ->dailyAt('07:00')
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
