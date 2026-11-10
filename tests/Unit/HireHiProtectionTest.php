<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

test('a 403 on a detail stops the collection before the next request', function () {
    $originalStorage = storage_path();
    $testStorage = sys_get_temp_dir().'/hirehi-test-'.bin2hex(random_bytes(8));
    app()->useStoragePath($testStorage);
    Http::preventStrayRequests();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/api/search/jobs')) {
            if ($request['include_counts'] === 'true') {
                return Http::response(['filter_counts' => ['format' => ['офис' => 2]]]);
            }

            return Http::response(['jobs' => [['id' => '1', 'title' => 'One'], ['id' => '2', 'title' => 'Two']]]);
        }

        return Http::response([], 403);
    });

    try {
        $this->artisan('hirehi:parse-vacancies', [
            '--request-delay-min-ms' => 2000,
            '--request-delay-max-ms' => 2000,
            '--max-retries' => 0,
        ])->assertFailed();

        Http::assertSentCount(3);
        expect(File::exists(storage_path('app/private/hirehi.disabled')))->toBeTrue();
    } finally {
        app()->useStoragePath($originalStorage);
        File::deleteDirectory($testStorage);
    }
});
