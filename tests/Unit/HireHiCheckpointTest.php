<?php

use App\Services\YandexFeedXmlFormat;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->testStorage = sys_get_temp_dir() . '/hirehi-test-' . bin2hex(random_bytes(8));
    mkdir($this->testStorage . '/app/private', 0700, true);
    app()->useStoragePath($this->testStorage);
    Http::preventStrayRequests();
});

test('повторный проход восстанавливает карточку и пропускает недоступную', function () {
    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => Http::sequence()
            ->push(['filter_counts' => ['format' => ['офис' => 2]]])
            ->push(['jobs' => [['id' => 1, 'title' => 'First'], ['id' => 2, 'title' => 'Second']]]),
        'https://hirehi.ru/api/jobs/1' => Http::sequence()->push([], 404)->push(['description' => 'Ready']),
        'https://hirehi.ru/api/jobs/2' => Http::response([], 404),
    ]);
    $this->mock(YandexFeedXmlFormat::class, fn ($mock) => $mock->shouldReceive('createXmlFeed')->once()
        ->withArgs(fn (array $vacancies) => count($vacancies) === 1 && $vacancies[0]['description'] === 'Ready'));
    $this->artisan('hirehi:parse-vacancies', ['--max-retries' => 0, '--detail-retry-passes' => 1, '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000])->assertSuccessful();
    $reports = glob($this->testStorage . '/app/private/hirehi-checkpoints/*/report-*.json');
    expect($reports)->toHaveCount(1);
    $report = json_decode(file_get_contents($reports[0]), true);
    expect($report['failed'])->toHaveCount(1)->and($report['failed'][0]['id'])->toBe('2');
});

test('возобновление использует сохранённые карточки после остановки на 403', function () {
    Http::fake([
        'https://hirehi.ru/api/search/jobs*' => Http::sequence()
            ->push(['filter_counts' => ['format' => ['офис' => 2]]])
            ->push(['jobs' => [['id' => 1, 'title' => 'First'], ['id' => 2, 'title' => 'Second']]]),
        'https://hirehi.ru/api/jobs/1' => Http::response(['description' => 'First']),
        'https://hirehi.ru/api/jobs/2' => Http::sequence()->push([], 403)->push(['description' => 'Second']),
    ]);
    $options = ['--max-retries' => 0, '--detail-retry-passes' => 0, '--request-delay-min-ms' => 2000, '--request-delay-max-ms' => 2000];
    $this->artisan('hirehi:parse-vacancies', $options)->assertFailed();
    Http::assertSentCount(4);
    expect(glob($this->testStorage . '/app/private/hirehi-checkpoints/*/detail-*.json'))->toHaveCount(1);
    @unlink($this->testStorage . '/app/private/hirehi.disabled');
    $this->mock(YandexFeedXmlFormat::class, fn ($mock) => $mock->shouldReceive('createXmlFeed')->once()
        ->withArgs(fn (array $vacancies) => count($vacancies) === 2));
    $this->artisan('hirehi:parse-vacancies', $options)->assertSuccessful();
    Http::assertSentCount(5);
});
