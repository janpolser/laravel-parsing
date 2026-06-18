<?php

use App\Console\Commands\Yandex\VacancyYandex;

it('extracts full Yandex vacancy description sections from rendered HTML', function () {
    $html = <<<'HTML'
<main class="Vacancy_vacancy__v__uk">
    <section class="lc-jobs-common-section">
        <div class="lc-jobs-common-section__content">
            <div class="lc-styled-text__text">
                Наша команда разрабатывает HR-систему для складов Маркета.</p>
                <p>Сейчас мы расширяем своё решение на Яндекс Лавку.</p>
            </div>
        </div>
    </section>
    <section class="lc-jobs-common-section">
        <div class="lc-jobs-common-section__section-title">
            <div class="lc-styled-text"><h3 class="lc-styled-text__text">Какие задачи вас ждут</h3></div>
        </div>
        <div class="lc-jobs-common-section__content">
            <div class="lc-styled-text__text">
                <b>Проектирование и разработка</b><br/>
                Разработчики сами уточняют корнер-кейсы и реализуют решения.
            </div>
        </div>
    </section>
    <section class="lc-jobs-common-section">
        <div class="lc-jobs-common-section__section-title">
            <div class="lc-styled-text"><h3 class="lc-styled-text__text">Мы ждем, что вы</h3></div>
        </div>
        <div class="lc-jobs-common-section__content">
            <div class="lc-styled-text__text">
                <ul>
                    <li>Работали с Java 17+ или Kotlin</li>
                    <li>Хорошо знаете SQL</li>
                </ul>
            </div>
        </div>
    </section>
    <section class="lc-jobs-benefits-block">
        <h3>Что мы предлагаем</h3>
        <div>Здоровье</div>
    </section>
</main>
HTML;

    $method = new ReflectionMethod(VacancyYandex::class, 'extractDescriptionFromHtml');

    $description = $method->invoke(new VacancyYandex(), $html);

    expect($description)
        ->toContain('Наша команда разрабатывает HR-систему для складов Маркета.')
        ->toContain('Сейчас мы расширяем своё решение на Яндекс Лавку.')
        ->toContain("Какие задачи вас ждут:\nПроектирование и разработка")
        ->toContain('- Работали с Java 17+ или Kotlin')
        ->not->toContain('Здоровье');
});

it('composes full Yandex vacancy description from detail API fields', function () {
    $publication = [
        'description' => "Наша команда разрабатывает HR-систему для складов Маркета.\r\n\r\nСейчас мы расширяем своё решение.",
        'duties' => "<b>Проектирование и разработка</b><br/>\r\nРазработчики сами проектируют архитектуру решения.",
        'key_qualifications' => "* Работали с Java 17+ или Kotlin\r\n* Хорошо знаете SQL",
        'conditions' => '',
    ];

    $method = new ReflectionMethod(VacancyYandex::class, 'composeDescriptionFromPublication');

    $description = $method->invoke(new VacancyYandex(), $publication);

    expect($description)
        ->toContain('Наша команда разрабатывает HR-систему для складов Маркета.')
        ->toContain("Какие задачи вас ждут:\nПроектирование и разработка")
        ->toContain('Разработчики сами проектируют архитектуру решения.')
        ->toContain("Мы ждем, что вы:\n- Работали с Java 17+ или Kotlin")
        ->toContain('- Хорошо знаете SQL');
});

it('extracts Yandex publication identifier from url slug or id input', function () {
    $method = new ReflectionMethod(VacancyYandex::class, 'publicationIdentifierFromInput');
    $command = new VacancyYandex();

    expect($method->invoke($command, 'https://yandex.ru/jobs/vacancies/razrabotchik-na-javakotlin-v-komandu-hrsistemi-dlya-skladov-logistiki-44946'))
        ->toBe('razrabotchik-na-javakotlin-v-komandu-hrsistemi-dlya-skladov-logistiki-44946')
        ->and($method->invoke($command, 'razrabotchik-na-javakotlin-v-komandu-hrsistemi-dlya-skladov-logistiki-44946'))
        ->toBe('razrabotchik-na-javakotlin-v-komandu-hrsistemi-dlya-skladov-logistiki-44946')
        ->and($method->invoke($command, '44946'))
        ->toBe('44946');
});
