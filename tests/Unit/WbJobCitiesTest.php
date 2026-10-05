<?php

use App\Services\WbJobCities;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('wb job city provider paginates and normalizes cities', function () {
    Http::fake([
        'https://wbk.wb.ru/community-utils/api/feedback/city' => Http::sequence()
            ->push(['cities' => [
                ['id' => 1, 'city' => 'Москва'],
                ['id' => 'invalid', 'city' => 'Пропустить'],
            ]])
    ]);

    $cities = (new WbJobCities())->all();

    expect($cities)->toBe([['id' => 1, 'name' => 'Москва']]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://wbk.wb.ru/community-utils/api/feedback/city'
            && $request['limit'] === 500
            && $request['offset'] === 0;
    });
});
