<?php

use App\Services\YandexFeedXmlFormat;

test('xml feed writer publishes complete file and skips invalid vacancies', function () {
    $filePath = dirname(__DIR__, 2) . '/storage/app/testing-feed.xml';
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    if (file_exists($filePath . '.tmp')) {
        unlink($filePath . '.tmp');
    }

    $formatter = new YandexFeedXmlFormat();
    $formatter->createXmlFeed([
        [
            'url' => 'https://example.test/vacancy/1',
            'creation_date' => '2026-06-21 10:00:00 GMT+3',
            'job_name' => 'Тестовая вакансия',
            'description' => 'Описание',
            'company_name' => 'Компания',
            'addresses' => [
                'address' => [
                    'location' => 'Москва',
                    'lat' => '55.7558',
                    'lng' => '37.6173',
                ],
            ],
            'hr_agency' => false,
        ],
        [
            'url' => 'https://example.test/vacancy/invalid',
            'creation_date' => '2026-06-21 10:00:00 GMT+3',
            'job_name' => 'Без описания',
            'company_name' => 'Компания',
        ],
    ], 'example.test', $filePath);

    expect(file_exists($filePath))->toBeTrue();
    expect(file_exists($filePath . '.tmp'))->toBeFalse();

    $xml = simplexml_load_file($filePath);

    expect($xml)->not->toBeFalse();
    expect((string) $xml->vacancies->vacancy->{'job-name'})->toBe('Тестовая вакансия');
    expect($xml->vacancies->vacancy)->toHaveCount(1);

    if (file_exists($filePath)) {
        unlink($filePath);
    }
});

test('xml feed writer moves urls from vacancy text fields into text urls', function () {
    $filePath = dirname(__DIR__, 2) . '/storage/app/testing-feed-text-urls.xml';
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    if (file_exists($filePath . '.tmp')) {
        unlink($filePath . '.tmp');
    }

    $formatter = new YandexFeedXmlFormat();
    $formatter->createXmlFeed([
        [
            'url' => 'https://example.test/vacancy/1',
            'creation_date' => '2026-06-21 10:00:00 GMT+3',
            'job_name' => 'Vacancy',
            'description' => 'Intro https://example.test/about?x=1&y=2 text.',
            'duty' => "Do work at https://example.test/duty.\nThen report.",
            'term' => [
                'contract' => 'indefinite',
                'text' => 'Docs: https://files.example.test/rule.pdf.',
            ],
            'requirement' => [
                'qualification' => 'Testing: https://mintrud.gov.ru/testing/default/view/3.',
            ],
            'company_name' => 'Company',
        ],
    ], 'example.test', $filePath);

    $xml = simplexml_load_file($filePath);
    $vacancy = $xml->vacancies->vacancy;

    expect((string) $vacancy->description)->toBe('Intro text.');
    expect((string) $vacancy->duty)->toBe("Do work at.\nThen report.");
    expect((string) $vacancy->term->text)->toBe('Docs.');
    expect((string) $vacancy->requirement->qualification)->toBe('Testing.');
    expect($vacancy->{'text-urls'}->url)->toHaveCount(4);
    expect((string) $vacancy->{'text-urls'}->url[0])->toBe('https://example.test/about?x=1&y=2');
    expect((string) $vacancy->{'text-urls'}->url[1])->toBe('https://example.test/duty');
    expect((string) $vacancy->{'text-urls'}->url[2])->toBe('https://files.example.test/rule.pdf');
    expect((string) $vacancy->{'text-urls'}->url[3])->toBe('https://mintrud.gov.ru/testing/default/view/3');

    if (file_exists($filePath)) {
        unlink($filePath);
    }
});
