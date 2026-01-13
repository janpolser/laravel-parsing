<?php

use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

// Яндекс
Schedule::command('yandex:vacancy-yandex')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

Schedule::command('yandex:prepare-data-to-format')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

// РЖД
Schedule::command('rzhd:collect-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

Schedule::command('wb:generate-courier-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-ce-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-b-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

Schedule::command('wb:generate-storage-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();

// Купер
Schedule::command('kuper:collect-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->runInBackground(false)
    ->withoutOverlapping();
