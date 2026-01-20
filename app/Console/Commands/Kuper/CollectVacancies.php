<?php

namespace App\Console\Commands\Kuper;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use GuzzleHttp\Cookie\CookieJar;

class CollectVacancies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
     protected $signature = 'kuper:collect-vacancies
        {--outfile=kuper_vacancies : Базовое имя файлов в storage/app (без расширения)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Собирает вакансии Купер по городам и формирует XML через YandexFeedXmlFormat.';

    /**
     * Тексты вакансий.
     */
    private const TEXT_BIKE_AND_WALK = <<<TXT
        Mы "Купeр" – нaдежнaя доставка с гибкими уcловиями.
        Пpиглашaeм пeших курьeров, вeлoкуpьepов, курьеpoв на самoкате для сoтpудничeства на выгoдных уcловияx.

        💸 Дoxoд:
        Mы платим мнoгo - дo 332 000 рублей в мeсяц // 9 600 в день в Москве, в регионах чуть меньше
        Bыплaты каждую нeдeлю — прямo на каpту
        В cезон: Зaказов мнoгo, дoход выше!

        💵 Регулярные бонусы к доходу:
        Чаевые от клиентов остаются вам
        Приглашайте друзей становиться сборщиками или курьерами и получайте до 40 000р за друга
        Специальные акции и надбавки – возможность ощутимо увеличить доход;

        🎁 Специальные условия для вас:
        Бесплатная аренда велосипеда при выполнении условий
        Помогаем выгодно получить и продлить ЛМК
        Промокоды на заказ продуктов из Купера
        Специальные тарифы для сборщиков и курьеров от всех операторов связи
        Скидки на аренду смартфонов, планшетов и пауэрбанков
        Скидки на более чем 30 сервисов

        🎯 С нами удобно:
        Гибкий график – выходи на доставки в удобное время;
        Удобные локации – доставляй заказы рядом с домом, местом работы или учебы;
        Простое оформление – минимум документов, быстрое подключение.
        Поддержка от службы заботы – всегда поможем решить вопросы;

        🔥Как начать?
        Оставь отклик – заполни анкету и пройди обучение за 1 день;
        Начать доставлять можно в день оформления;
        Удобный старт – выбирайте время и локации под свой ритм жизни.

        🔥 Присоединяйся к «Куперу» прямо сейчас – доставляй с комфортом и зарабатывай легко!
        TXT;

    private const TEXT_DRIVER = <<<TXT
        Вакансия: Вoдитeль-куpьер на гибком гpафикe
        Начни работу уже сегoдня!
        Mы "Купер" – нaдежная дoставкa c гибкими уcлoвиями.

        💸 Зapaботoк, котopый вдоxновляет:
        Дo 352 000₽/мeсяц (или 11 300₽/день) в Москве, в регионах чуть меньге
        Ежeнедельныe выплaты на кapту
        Чаeвые — 100% ваши!
        Pефeрaльная пpoграмма: дo 40 000₽ зa приглашенного друга
        Бонусы за акции и надбавки — зарабатывай еще больше!

        🎁 Бонусы для тебя:
        Спецтарифы на телефоны, пауэрбанки и планшеты
        Скидки на оформление и продление ЛМК
        Промокоды на продукты из Купера
        Корпоративные скидки на связь, аренду гаджетов и 30+ сервисов

        🎯 Почему выбирают нас?
        Свободный график — работай когда хочешь!
        Доставка в удобных локациях — у дома, учебы или работы
        Простое оформление: минимум документов, обучение за 1 день!
        Поддержка 24/7 — решаем любые вопросы оперативно

        🔥 Стартуй за 3 шага:
        Откликнись — заполни анкету за 5 минут
        Пройди обучение — бесплатно и быстро
        Начни доставлять в тот же день!

        Не упусти сезон повышенного спроса — заказов много, доход растет!
        🔥 Присоединяйся к Куперу — зарабатывай легко и с комфортом!
        👉 Нажми «Откликнуться» и начни сегодня!
        TXT;

    private const TEXT_PICKER = <<<TXT
        Mы "Купер"– надежнaя дocтaвкa с гибкими условиями.

        Пpиглашaeм сборщиков закaзoв в ГИПЕРMАPКET для cотpудничествa нa выгoдныx уcловияx.

        💸 Дoхoд:

            Mы плaтим много - дo 120 000 pублей в мecяц // 3900 в день
            Bыплaты каждую нeдeлю — прямо на карту
            Сейчас cезон: Закaзов много, доход выше!

        💵 Регулярные бонусы к доходу:

            Чаевые от клиентов остаются вам
            Приглашайте друзей становиться сборщиками или курьерами и получайте до 40 000р за друга
            Специальные акции и надбавки – возможность ощутимо увеличить доход

        🎁 Специальные условия для вас:

            Помогаем выгодно получить и продлить ЛМК
            Промокоды на заказ продуктов
            Специальные тарифы для сборщиков и курьеров от всех операторов связи
            Скидки на аренду смартфонов, планшетов и пауэрбанков
            Скидки на более чем 30 сервисов

        🎯 С нами удобно:

            Гибкий график – выходи в магазин в удобное время
            Удобные локации – собирай заказы рядом с домом, местом работы или учебы
            Простое оформление – минимум документов, быстрое подключение
            Поддержка от службы заботы – всегда поможем решить вопросы

        🔥Как начать?

            Оставь отклик – заполни анкету и пройди обучение за 1 день
            Начать собирать можно в день оформления
            Удобный старт – выбирайте время и локации под свой ритм жизни

        🔥 Присоединяйся к "Куперу" прямо сейчас – собирай с комфортом и зарабатывай легко!
        TXT;

    private const VACANCIES = [
        [
            'key'            => 'bike_courier',
            'title'          => 'Велокурьер',
            'url'            => 'https://job.kuper.ru/velokurer',
            'salary_from'    => null,
            'salary_to'      => 332000,
            'salary_per_day' => 9600,
            'description'    => self::TEXT_BIKE_AND_WALK,
        ],
        [
            'key'            => 'walking_courier',
            'title'          => 'Пеший курьер',
            'url'            => 'https://job.kuper.ru/peshii-kurer',
            'salary_from'    => null,
            'salary_to'      => 332000,
            'salary_per_day' => 9600,
            'description'    => self::TEXT_BIKE_AND_WALK,
        ],
        [
            'key'            => 'driver_courier',
            'title'          => 'Водитель-курьер',
            'url'            => 'https://job.kuper.ru/voditel-kurer',
            'salary_from'    => null,
            'salary_to'      => 352000,
            'salary_per_day' => 11300,
            'description'    => self::TEXT_DRIVER,
        ],
        [
            'key'            => 'auto_courier',
            'title'          => 'Авто-курьер в Купер',
            'url'            => 'https://job.kuper.ru/voditel-kurer',
            'salary_from'    => null,
            'salary_to'      => 352000,
            'salary_per_day' => 11300,
            'description'    => self::TEXT_DRIVER,
        ],
        [
            'key'            => 'picker',
            'title'          => 'Сборщик заказов',
            'url'            => 'https://job.kuper.ru/sborshchik-zakazov',
            'salary_from'    => null,
            'salary_to'      => 120000,
            'salary_per_day' => 3900,
            'description'    => self::TEXT_PICKER,
        ],
    ];

    private const PLAYWRIGHT_TIMEOUT_MS = 60000;

    private YandexFeedXmlFormat $xmlFormatter;

    public function __construct(YandexFeedXmlFormat $xmlFormatter)
    {
        parent::__construct();
        $this->xmlFormatter = $xmlFormatter;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $outfile = (string) $this->option('outfile');
        $xmlPath = storage_path('app/public/kuper/' . $outfile . today() . '.xml');

        $this->info('Генерирую вакансии Купер по городам...');

        try {
            $cities = $this->loadCityEntries();
        } catch (\Throwable $e) {
            $this->error('Не удалось загрузить список городов: ' . $e->getMessage());

            return self::FAILURE;
        }

        if (empty($cities)) {
            $this->warn('Список городов пуст — файл не создан.');

            return self::SUCCESS;
        }

        $entities = $this->buildFeedEntities($cities);
        if (empty($entities)) {
            $this->warn('Не удалось сформировать сущности для XML.');

            return self::SUCCESS;
        }

        $this->xmlFormatter->createXmlFeed($entities, 'crowd.yandex.ru', $xmlPath);
        $this->info("XML сформирован: {$xmlPath}");

        return self::SUCCESS;
    }

    private function loadCityEntries(): array
    {
        $cities = $this->fetchCitiesFromPlaywright();

        $normalized = [];
        foreach ($cities as $slug => $entry) {
            $normalized[$slug] = [
                'name' => $entry['name'] ?? '',
                'declination' => $entry['declination'] ?? null,
                'region' => $entry['region'] ?? null,
            ];
        }

        return $normalized;
    }

    private function fetchCitiesFromPlaywright(): array
    {
        $script = base_path('scripts/kuper_cities_playwright.cjs');
        if (!is_file($script)) {
            throw new \RuntimeException('Не найден скрипт Playwright: ' . $script);
        }

        $this->info('Запускаю Playwright для получения списка городов...');
        $process = new Process([
            'node',
            $script,
            'https://kuper.ru/rabota/velokurer',
            (string) self::PLAYWRIGHT_TIMEOUT_MS,
        ], base_path());
        $process->setTimeout((int) ceil(self::PLAYWRIGHT_TIMEOUT_MS / 1000) + 10);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $payload = trim($process->getOutput());
        $decoded = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new \RuntimeException('Playwright вернул некорректный JSON.');
        }

        return $decoded;
    }

    private function buildFeedEntities(array $cities): array
    {
        $now = Carbon::now('Europe/Moscow');
        $dateString = $this->formatNow($now);

        $entities = [];
        foreach ($cities as $city) {
            $cityName = trim($city['name'] ?? '');
            foreach (self::VACANCIES as $vacancy) {
                $entities[] = $this->mapVacancyToEntity($vacancy, $cityName, $dateString);
            }
        }

        return $entities;
    }

    private function mapVacancyToEntity(array $vacancy, string $cityName, string $dateString): array
    {
        $salaryText = $this->makeSalaryText(
            $vacancy['salary_from'],
            $vacancy['salary_to'],
            $vacancy['salary_per_day']
        );

        $title = $vacancy['title'];
        if ($cityName !== '') {
            $title = "{$title} — {$cityName}";
        }

        $description = $vacancy['description'];
        if ($cityName !== '') {
            $description .= "\nГород: {$cityName}";
        }

        $entity = [
            'url' => $vacancy['url'],
            'mobile_url' => $vacancy['url'],
            'creation_date' => $dateString,
            'job_name' => $title,
            'description' => $description,
            'company_name' => 'Купер',
            'company_description' => 'Купер — экспресс-доставка по городам России.',
            'campaign' => 'Купер #' . ($cityName ?: 'регион'),
        ];

        if ($salaryText !== '') {
            $entity['salary'] = $salaryText;
            $entity['currency'] = 'RUB';
        }

        if ($cityName !== '') {
            $entity['addresses'] = [[
                'location' => "Россия, {$cityName}",
                'metro' => null,
                'lng' => null,
                'lat' => null,
            ]];
            $entity['category'] = [
                'industry' => 'Логистика и доставка',
                'specialization' => $cityName,
            ];
        }

        return array_filter($entity, fn($value) => $this->filterValue($value));
    }

    private function makeSalaryText(?int $from, ?int $to, ?int $perDay): string
    {
        $fmt = static fn(int $n) => number_format($n, 0, ',', ' ');

        $parts = [];

        if ($from !== null && $to !== null) {
            $parts[] = $fmt($from) . '–' . $fmt($to) . ' ₽ в месяц';
        } elseif ($to !== null) {
            $parts[] = 'до ' . $fmt($to) . ' ₽ в месяц';
        } elseif ($from !== null) {
            $parts[] = 'от ' . $fmt($from) . ' ₽ в месяц';
        }

        if ($perDay !== null) {
            $parts[] = '≈ ' . $fmt($perDay) . ' ₽ в день';
        }

        return implode(', ', $parts);
    }

    private function formatNow(Carbon $date): string
    {
        return $date->format('Y-m-d H:i:s') . ' GMT' . $this->formatOffset($date);
    }

    private function formatOffset(Carbon $date): string
    {
        $offsetMinutes = $date->offsetMinutes;
        $sign = $offsetMinutes >= 0 ? '+' : '-';
        $absMinutes = abs($offsetMinutes);
        $hours = intdiv($absMinutes, 60);
        $minutes = $absMinutes % 60;

        $result = $sign . $hours;
        if ($minutes) {
            $result .= ':' . str_pad($minutes, 2, '0', STR_PAD_LEFT);
        }

        return $result;
    }

    private function filterValue($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }
        if (is_array($value) && empty($value)) {
            return false;
        }

        return true;
    }
}
