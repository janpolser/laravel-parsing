<?php

use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

// Яндекс
Schedule::command('yandex:vacancy-yandex')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

Schedule::command('yandex:prepare-data-to-format')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

// РЖД
Schedule::command('rzhd:collect-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

Schedule::command('wb:generate-courier-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

Schedule::command('wb:generate-driver-ce-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

Schedule::command('wb:generate-driver-b-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

Schedule::command('wb:generate-storage-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);

// Купер
Schedule::command('wb:generate-courier-vacancies')
    ->weeklyOn(1, '8:00')
    ->runInBackground(false);
