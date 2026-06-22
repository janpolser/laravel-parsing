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
