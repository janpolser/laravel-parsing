<?php

use App\Console\Commands\Magnit\CollectVacancy;

test('magnit vacancy feed uses detail fields in description and structured nodes', function () {
    $command = new CollectVacancy();
    $method = new ReflectionMethod($command, 'mapVacancyToFeed');
    $method->setAccessible(true);

    $vacancy = [
        'id' => '6a227be5329ae1f0cc69df5d',
        'name' => 'Бренд менеджер',
        'salary_human' => 'Зарплата по результатам собеседования',
        'responsibilities' => [
            'Разрабатывать новые бренды',
            'Анализировать рынок',
        ],
        'professional_skills' => [
            'Высшее образование;',
            'Опыт работы с портфелем бренда;',
        ],
        'professional_experience' => [
            'Работу в сплоченной команде,',
            'Корпоративное обучение;',
        ],
        'properties' => [
            'business_directions' => [
                ['name' => 'Маркетинг'],
            ],
            'block_org_unit' => [
                'id' => '61f2bb2cadcd1cd2c9f6a5d8',
                'name' => 'СТМ',
            ],
        ],
        'schedule_title' => 'Пятидневка',
        'address' => 'Россия, г Москва, Бумажный проезд',
        'basic_latitude' => '55.7884667',
        'basic_longitude' => '37.5860279',
    ];

    $feed = $method->invoke($command, $vacancy, ['slug' => 'moskva'], new DateTime('2026-06-17 10:00:00'));

    expect($feed['description'])->toContain("Обязанности:\nРазрабатывать новые бренды;\nАнализировать рынок");
    expect($feed['description'])->toContain("Требования:\nВысшее образование;\nОпыт работы с портфелем бренда");
    expect($feed['description'])->toContain("Условия:\nРаботу в сплоченной команде;\nКорпоративное обучение");
    expect($feed['requirement']['qualification'])->toContain('Высшее образование');
    expect($feed['term']['text'])->toContain('Корпоративное обучение');
    expect($feed['category'])->toBe([
        'industry' => 'Маркетинг',
        'specialization' => 'СТМ',
    ]);
    expect($feed['addresses']['address']['lat'])->toBe('55.7884667');
    expect($feed['hr_agency'])->toBeFalse();
});
