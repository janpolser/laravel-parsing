<?php

use App\Services\YandexFeedXmlFormat;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('hirehi creates a feed when work format counters are omitted', function (array $counts) {
    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => Http::sequence()
            ->push($counts)
            ->push(['jobs' => [[
                'id' => 123,
                'title' => 'PHP Developer',
                'company' => 'Test Company',
                'format' => 'удалённо',
            ]]]),
        'https://hirehi.ru/api/jobs/123' => Http::response(['description' => 'Build applications']),
    ]);

    $this->mock(YandexFeedXmlFormat::class, function ($mock) {
        $mock->shouldReceive('createXmlFeed')->once()
            ->withArgs(function (array $vacancies, string $host, string $path) {
                return count($vacancies) === 1
                    && $vacancies[0]['job_name'] === 'PHP Developer'
                    && $vacancies[0]['description'] === 'Build applications'
                    && $host === 'https://hirehi.ru/'
                    && str_starts_with($path, 'storage/app/public/hirehi/hirehi_development_');
            });
    });

    $this->artisan('hirehi:parse-vacancies', ['--request-delay-ms' => 250, '--max-retries' => 0])
        ->assertSuccessful();

    Http::assertSentCount(3);
})->with([
    'missing remote Russia counter' => [['filter_counts' => ['format' => ['гибрид' => 0, 'офис' => 0, 'удалённо' => 1]]]],
    'only hybrid counter' => [['filter_counts' => ['format' => ['гибрид' => 1]]]],
    'only office counter' => [['filter_counts' => ['format' => ['офис' => 1]]]],
    'only remote Russia counter' => [['filter_counts' => ['format' => ['удалённо по РФ' => 1]]]],
    'initial counters fallback' => [['initial_filter_counts' => ['format' => ['удалённо' => 1]]]],
    'numeric strings and null counters' => [['filter_counts' => ['format' => ['гибрид' => null, 'офис' => '1']]]],
]);

test('hirehi preserves pagination and the requested page limit with incomplete counters', function (int $maxPages, int $expectedPages) {
    $pages = Http::sequence()->push(['filter_counts' => ['format' => ['офис' => 101]]]);
    for ($page = 1; $page <= $expectedPages; $page++) {
        $pages->push(['jobs' => [[
            'id' => $page,
            'title' => 'Developer ' . $page,
            'company' => 'Test Company',
        ]]]);
    }

    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => $pages,
        'https://hirehi.ru/api/jobs/*' => Http::response(['description' => 'Build applications']),
    ]);

    $this->mock(YandexFeedXmlFormat::class, function ($mock) use ($expectedPages) {
        $mock->shouldReceive('createXmlFeed')->once()
            ->withArgs(fn (array $vacancies, string $host, string $path) => count($vacancies) === $expectedPages);
    });

    $this->artisan('hirehi:parse-vacancies', [
        '--max-pages' => $maxPages,
        '--request-delay-ms' => 250,
        '--max-retries' => 0,
    ])->assertSuccessful();

    $requestedPages = Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://hirehi.ru/api/search/jobs'))
        ->map(fn ($pair) => (int) $pair[0]['page'])->values()->all();
    expect($requestedPages)->toBe(array_merge([1], range(1, $expectedPages)));
    Http::assertSentCount(1 + 2 * $expectedPages);
})->with([
    'all pages' => [0, 2],
    'one page limit' => [1, 1],
]);

test('hirehi can fetch only a bounded page range for feed reconciliation', function () {
    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => Http::sequence()
            ->push(['filter_counts' => ['format' => ['офис' => 14687]]])
            ->push(['jobs' => [['id' => 145, 'title' => 'Developer 145', 'company' => 'Test Company']]])
            ->push(['jobs' => [['id' => 146, 'title' => 'Developer 146', 'company' => 'Test Company']]]),
        'https://hirehi.ru/api/jobs/*' => Http::response(['description' => 'Build applications']),
    ]);

    $this->mock(YandexFeedXmlFormat::class, function ($mock) {
        $mock->shouldReceive('createXmlFeed')->once()
            ->withArgs(fn (array $vacancies, string $host, string $path) => count($vacancies) === 2);
    });

    $this->artisan('hirehi:parse-vacancies', [
        '--start-page' => 145,
        '--end-page' => 146,
        '--request-delay-ms' => 250,
        '--max-retries' => 0,
    ])->assertSuccessful();

    $requestedPages = Http::recorded(fn ($request) => str_starts_with($request->url(), 'https://hirehi.ru/api/search/jobs'))
        ->map(fn ($pair) => (int) $pair[0]['page'])->values()->all();
    expect($requestedPages)->toBe([1, 145, 146]);
    Http::assertSentCount(5);
});

test('hirehi does not generate a feed when filtered counters are empty', function (array $formatCounts) {
    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => Http::response([
            'filter_counts' => ['format' => $formatCounts],
            'initial_filter_counts' => ['format' => ['офис' => 100]],
        ]),
    ]);
    $this->mock(YandexFeedXmlFormat::class, fn ($mock) => $mock->shouldNotReceive('createXmlFeed'));

    $this->artisan('hirehi:parse-vacancies', ['--max-retries' => 0])
        ->expectsOutput('Нет вакансий для обработки')
        ->assertFailed();

    Http::assertSentCount(1);
})->with([
    'empty counters' => [[]],
    'zero counters' => [['офис' => 0]],
]);
