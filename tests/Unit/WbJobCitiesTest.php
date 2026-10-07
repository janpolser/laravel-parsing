<?php

use App\Services\WbJobCities;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('wb job city provider normalizes areas json', function () {
    Http::fake([
        'https://job.wb.ru/assets/data/areas.json' => Http::response([
            ['id' => '1', 'name' => 'Москва'],
            ['id' => 'invalid', 'name' => 'Пропустить'],
            ['id' => '2', 'name' => ''],
            ['id' => '1', 'name' => 'Москва'],
        ]),
    ]);

    $cities = (new WbJobCities)->all();

    expect($cities)->toBe([['id' => 1, 'name' => 'Москва']]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://job.wb.ru/assets/data/areas.json'
            && $request->method() === 'GET';
    });
});
