<?php

use App\Services\Ozon\VacancyCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

function ozonApiItem(int $id): array
{
    return [
        'internalUuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
        'title' => 'Вакансия '.$id,
        'vacancyType' => 'external_vacancy',
    ];
}

function ozonApiPage(array $ids, int $page, int $total): array
{
    return [
        'items' => array_map('ozonApiItem', $ids),
        'meta' => ['page' => $page, 'perPage' => 2, 'totalItems' => $total, 'totalPages' => (int) ceil($total / 2)],
    ];
}

test('ozon API catalog follows metadata and validates the complete unique result', function () {
    Http::preventStrayRequests();
    Http::fake(fn (Request $request) => match ((int) $request['meta.page']) {
        1 => Http::response(ozonApiPage([1, 2], 1, 3)),
        2 => Http::response(ozonApiPage([3], 2, 3)),
        default => Http::response([], 500),
    });

    $result = app(VacancyCatalog::class)->collect('Москва');

    expect(array_column($result, 'title'))->toBe(['Вакансия 1', 'Вакансия 2', 'Вакансия 3']);
    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['city'] === 'Москва' && (int) $request['meta.limit'] === 50);
});

test('ozon API catalog rejects incomplete or inconsistent pages', function ($second, int $status) {
    Http::preventStrayRequests();
    Http::fakeSequence()->push(ozonApiPage([1, 2], 1, 3))->push($second, $status);

    expect(fn () => app(VacancyCatalog::class)->collect('Москва'))->toThrow(RuntimeException::class);
})->with([
    'HTTP failure' => [[], 503],
    'empty page' => [ozonApiPage([], 2, 3), 200],
    'duplicate' => [ozonApiPage([2], 2, 3), 200],
    'wrong page' => [ozonApiPage([3], 1, 3), 200],
]);

test('ozon tolerates a small change in the count without extra requests', function () {
    Http::preventStrayRequests();
    Http::fakeSequence()->push(ozonApiPage([1, 2], 1, 3))->push(ozonApiPage([3], 2, 4));
    expect(app(VacancyCatalog::class)->collect('Москва'))->toHaveCount(3);
    Http::assertSentCount(2);
});
