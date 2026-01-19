<?php

use Illuminate\Support\Facades\Schedule;

// Магнит
Schedule::command('magnit:collect-vacancy-magnit')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Яндекс
Schedule::command('yandex:vacancy-yandex')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('yandex:prepare-data-to-format')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Пятерочка
Schedule::command('pyaterochka:vacancy-5ka')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// РЖД
Schedule::command('rzhd:collect-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// WB
Schedule::command('wb:collect-wb-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-courier-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-ce-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-driver-b-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

Schedule::command('wb:generate-storage-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();

// Купер
Schedule::command('kuper:collect-vacancies')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();


// Создание архивов
Schedule::command('xml:tar')
    ->weeklyOn(1, '8:00')
    ->appendOutputTo('/proc/1/fd/1')
    ->withoutOverlapping();
