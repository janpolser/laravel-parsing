<?php

use App\Console\Commands\WB\CollectWbVacancies;
use App\Services\YandexFeedXmlFormat;

test('wb vacancy description preserves line breaks and list item breaks', function () {
    $command = new CollectWbVacancies(new YandexFeedXmlFormat());
    $method = new ReflectionMethod($command, 'composeDescription');
    $method->setAccessible(true);

    $description = $method->invoke($command, [
        'description' => "Первый абзац\n\nВторой    абзац",
        'requirements_arr' => [
            ' Глубокая экспертиза в Kubernetes;',
            'Практический опыт развертывания Kubernetes;',
        ],
        'conditions_arr' => [],
    ]);

    expect($description)->toContain("Первый абзац\n\nВторой абзац");
    expect($description)->toContain("Глубокая экспертиза в Kubernetes;\nПрактический опыт развертывания Kubernetes");
    expect($description)->not->toContain('Первый абзац Второй абзац');
});
