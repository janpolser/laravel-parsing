<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(Tests\TestCase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->lentaOriginalStoragePath = storage_path();
    $this->lentaStoragePath = sys_get_temp_dir().'/lenta-feed-'.bin2hex(random_bytes(8));
    app()->useStoragePath($this->lentaStoragePath);
    $this->lentaOutputPath = storage_path('app/public/lenta/lenta_vacancies'.today()->toDateString().'.xml');
});

afterEach(function () {
    app()->useStoragePath($this->lentaOriginalStoragePath);
    \Illuminate\Support\Facades\File::deleteDirectory($this->lentaStoragePath);
});

function lentaItem(int $id, string $city = 'Воронеж', bool $hidden = false): array
{
    return [
        'vacancyId' => $id, 'title' => 'Администратор '.$id,
        'responsibilities' => '<ul><li>Работа с покупателями</li></ul>',
        'requirements' => '<p>Опыт работы</p>', 'conditions' => '<p>Оформление</p>',
        'vacancyNumber' => (string) $id, 'subdivisionId' => null, 'subdivisionTitle' => null,
        'cityId' => 24, 'cityTitle' => $city, 'addressId' => 1, 'addressTitle' => 'Центральная, 1',
        'metro' => [], 'salaryFrom' => 45000, 'salaryTo' => null,
        'businessDirectionId' => 1, 'businessDirectionTitle' => 'Магазин', 'isHideOnCareer' => $hidden,
    ];
}

function lentaResponse(array $items, int $page = 0, int $pages = 1, int $total = 1): array
{
    return ['searchResult' => ['currentPage' => $page, 'items' => $items, 'itemsPerPage' => 100,
        'totalCount' => $total, 'totalPagesCount' => $pages]];
}

test('lenta collects every non-empty city, paginates and skips hidden vacancies', function () {
    $first = lentaItem(100);
    $second = lentaItem(101, 'Москва');
    Http::fake(function (Request $request) use ($first, $second) {
        if ((int) $request['cityId'] === 451) {
            return Http::response(['filters' => ['cities' => [
                ['cityId' => 24, 'title' => 'Воронеж', 'count' => 2],
                ['cityId' => 25, 'title' => 'Всеволожск', 'count' => 1],
            ]]]);
        }
        if ((int) $request['cityId'] === 24 && (int) $request['page'] === 0) {
            return Http::response(lentaResponse([$first], 0, 1, 1));
        }
        if ((int) $request['cityId'] === 24) {
            return Http::response(lentaResponse([$second], 0, 1, 1));
        }

        return Http::response(lentaResponse([lentaItem(102, 'Москва', true)], 0, 1, 1));
    });

    $this->artisan('lenta:collect-vacancies', ['--sleep-ms' => 0])->assertSuccessful();
    $xml = simplexml_load_file($this->lentaOutputPath);
    expect($xml->vacancies->vacancy)->toHaveCount(1)
        ->and((string) $xml->vacancies->vacancy[0]->{'job-name'})->toBe('Администратор 100')
        ->and((string) $xml->vacancies->vacancy[0]->description)->toContain("Обязанности:\n")
        ->and((string) $xml->vacancies->vacancy[0]->campaign)->toBe('Лента');
    Http::assertSentCount(3);
});

test('lenta preview is outside the archive source folder', function () {
    Http::fake(fn (Request $request) => (int) $request['cityId'] === 451
        ? Http::response(['filters' => ['cities' => [['cityId' => 24, 'title' => 'Воронеж', 'count' => 1]]]])
        : Http::response(lentaResponse([lentaItem(100)])));

    $this->artisan('lenta:collect-vacancies', ['--max-cities' => 1, '--sleep-ms' => 0])->assertSuccessful();
    expect(file_exists($this->lentaOutputPath))->toBeFalse()
        ->and(file_exists(storage_path('app/lenta-previews/lenta_vacancies'.today()->toDateString().'.xml')))->toBeTrue();
});
