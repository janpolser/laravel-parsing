<?php

namespace App\Console\Commands\Kuper;

use App\Services\YandexFeedXmlFormat;
use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
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

    private const CITY_CACHE_PATH = 'app/kuper/cities.json';

    private const DEFAULT_CITY_NAMES = [
        'Анадырь',
        'Ачинск',
        'Грозный',
        'Альметьевск',
        'Великий Новгород',
        'Абакан',
        'Бийск',
        'Калуга',
        'Белгород',
        'Комсомольск-на-Амуре',
        'Москва',
        'Горно-Алтайск',
        'Астрахань',
        'Биробиджан',
        'Владимир',
        'Набережные Челны',
        'Брянск',
        'Новокузнецк',
        'Братск',
        'Владивосток',
        'Кызыл',
        'Киров',
        'Нальчик',
        'Архангельск',
        'Кемерово',
        'Нижний Новгород',
        'Курган',
        'Ростов-на-Дону',
        'Омск',
        'Ижевск',
        'Тула',
        'Иркутск',
        'Барнаул',
        'Оренбург',
        'Новороссийск',
        'Орск',
        'Ставрополь',
        'Майкоп',
        'Красноярск',
        'Липецк',
        'Магадан',
        'Сочи',
        'Смоленск',
        'Калининград',
        'Новосибирск',
        'Тамбов',
        'Санкт-Петербург',
        'Кострома',
        'Благовещенск',
        'Воронеж',
        'Нижний Тагил',
        'Элиста',
        'Находка',
        'Самара',
        'Железногорск',
        'Южно-Сахалинск',
        'Томск',
        'Уссурийск',
        'Екатеринбург',
        'Волгоград',
        'Нижневартовск',
        'Рязань',
        'Вологда',
        'МО, Звенигород',
        'Владикавказ',
        'Мурманск',
        'Тобольск',
        'Псков',
        'Казань',
        'Йошкар-Ола',
        'Нарьян-Мар',
        'Прокопьевск',
        'Якутск',
        'Тюмень',
        'МО, Люберцы',
        'МО, Лобня',
        'Выкса',
        'Балахна',
        'МО, Химки',
        'МО, Ногинск',
        'Махачкала',
        'Саратов',
        'Чита',
        'Петрозаводск',
        'Магнитогорск',
        'Саранск',
        'Краснодар',
        'Пермь',
        'Иваново',
        'Таганрог',
        'МО, Мытищи',
        'Алексин',
        'Каменск-Уральский',
        'МО, Солнечногорск',
        'Улан-Удэ',
        'Сургут',
        'Чебоксары',
        'Пенза',
        'Тольятти',
        'МО, Балашиха',
        'Тверь',
        'Ульяновск',
        'МО, Пушкино',
        'Ханты-Мансийск',
        'Пятигорск',
        'МО, Домодедово',
        'Орел',
        'Уфа',
        'Ноябрьск',
        'МО, Котельники',
        'Черкесск',
        'МО, Одинцово',
        'МО, Подольск',
        'Чистополь',
        'МО, Видное',
        'Хабаровск',
        'Миасс',
        'МО, Реутов',
        'Сыктывкар',
        'МО, Апаринки',
        'Бугульма',
        'МО, Томилино',
        'Курск',
        'Стерлитамак',
        'МО, Щелково',
        'Юрга',
        'МО, Жуковский',
        'Ярославль',
        'МО, Красногорск',
        'МО, Зеленоград',
        'МО, Дзержинский',
        'МО, Лыткарино',
        'Рыбинск',
        'Александров',
        'МО, Долгопрудный',
        'МО, д. Ликино',
        'Челябинск',
        'МО, Истра',
        'МО, Раменское',
        'Петропавловск-Камчатский',
        'Ангарск',
        'Армавир',
        'МО, Московский',
        'Березники',
        'ЛО, Волхов',
        'ЛО, Всеволожск',
        'ЛО, Выборг',
        'ЛО, Гатчина',
        'Дивногорск',
        'ЛО, Кингисепп',
        'ЛО, Кириши',
        'ЛО, Колпино',
        'ЛО, Красное Село',
        'ЛО, Кронштадт',
        'Ленинск-Кузнецкий',
        'Лесосибирск',
        'Нижнекамск',
        'ЛО, Павловск',
        'Саяногорск',
        'ЛО, Сертолово',
        'ЛО, Сестрорецк',
        'Сосновоборск',
        'ЛО, Сосновый бор',
        'Черногорск',
        'ЛО, Пушкин',
        'ЛО, Тихвин',
        'МО, Королев',
        'МО, Троицк',
        'МО, Наро-Фоминск',
        'МО, Серпухов',
        'Дзержинск',
        'ЛО, Сосновый Бор',
        'Истра',
        'Муром',
        'Череповец',
        'Щекино',
        'Арзамас',
        'Артем',
        'Балаково',
        'Балтийск',
        'Белорецк',
        'Бердск',
        'Волжский',
        'Грязи',
        'Дедовск',
        'Димитровград',
        'Дмитров',
        'Железноводск',
        'Звенигород',
        'Зеленоградск',
        'Канск',
        'Кимры',
        'Ковров',
        'Магас',
        'МО, д.Елино',
        'МО, д. Картмазово',
        'МО, Дмитров',
        'МО, Дубна',
        'МО, Железнодорожный',
        'МО, Ивантеевка',
        'МО, Клин',
        'МО, Коломна',
        'МО, Можайск',
        'МО, Новые Псарьки',
        'МО, Озеры',
        'МО, Орехово-Зуево',
        'МО, Пущино',
        'МО, Сергиев Посад',
        'МО, с. Тарасовка',
        'МО, Ступино',
        'МО, Черная Грязь',
        'МО, Чехов',
        'МО, Электросталь',
        'Назрань',
        'Новочеркасск',
        'Новошахтинск',
        'Обнинск',
        'Павлово',
        'Салават',
        'Саров',
        'Светлогорск',
        'Северск',
        'Серов',
        'Сызрань',
        'Чапаевск',
        'Шахты',
        'Энгельс',
        'МО, Рассказовка',
        'Сосновый Бор',
        'Аксай',
        'Батайск',
        'Бор',
        'Ишим',
        'Кировск',
        'Кстово',
        'МО, Бронницы',
        'МО, Дедовск',
        'МО, Егорьевск',
        'МО, Павловский посад',
        'МО, Фрязино',
        'МО, Шатура',
        'Ростов Великий',
        'Шлиссельбург',
        'МО, Кашира',
        'Азов',
        'Александровское',
        'Анапа',
        'Асбест',
        'Балашов',
        'Белая Калитва',
        'Белово',
        'Бузулук',
        'Верхнерусское',
        'Верхняя Пышма',
        'Волгодонск',
        'Георгиевск',
        'Глазов',
        'Дербент',
        'Ейск',
        'Ессентуки',
        'Жигулевск',
        'Златоуст',
        'КК, Железногорск',
        'Канаш',
        'Каспийск',
        'Качканар',
        'Киселевск',
        'Копейск',
        'Котовск',
        'Краснокамск',
        'Кропоткин',
        'Крымск',
        'Кувандык',
        'Кумертау',
        'ЛО, Кировск',
        'ЛО, Красный Бор',
        'ЛО, Кудрово',
        'ЛО, Мурино',
        'Лениногорск',
        'Лермонтов',
        'Лиски',
        'Лобня',
        'Луховка',
        'МО, Андреевка',
        'МО, Высоковск',
        'МО, Дмитровское',
        'МО, Лопатино',
        'МО, Новодрожжино',
        'МО, Отрадное',
        'МО, Павлино',
        'МО, Подъячево',
        'МО, Сосны',
        'МО, Черное',
        'МО, Шолохово',
        'Мариинск',
        'Минеральные Воды',
        'Михайловск',
        'Морозовск',
        'Мытищи',
        'Невинномысск',
        'Нефтекамск',
        'Новокуйбышевск',
        'Новомосковск',
        'Новопавловск',
        'Новоуральск',
        'Новочебоксарск',
        'Обь',
        'Октябрьский',
        'Орёл',
        'Первоуральск',
        'Полевской',
        'Приморско-Ахтарск',
        'Прохладный',
        'Пугачев',
        'Рузаевка',
        'Севастополь',
        'Сибай',
        'Симферополь',
        'Славянск-на-Кубани',
        'Соликамск',
        'Темрюк',
        'Тимашевск',
        'Тихорецк',
        'Туапсе',
        'Узловая',
        'Усть-Лабинск',
        'Учалы',
        'Феодосия',
        'Чайковский',
        'Чернушка',
        'Чусовой',
        'Шадринск',
        'Шатура',
        'Южноуральск',
        'Апатиты',
        'Белореченск',
        'Березовский',
        'Бирск',
        'Благодарный',
        'Боровичи',
        'Великие Луки',
        'Великий Устюг',
        'Вичуга',
        'Волгореченск',
        'Волжск',
        'Воткинск',
        'Вышний Волочек',
        'Вятские Поляны',
        'Гай',
        'Геленджик',
        'Губкин',
        'Гуково',
        'Гусь-Хрустальный',
        'Донской',
        'Елабуга',
        'Елец',
        'Ефремов',
        'Заводоуковск',
        'Заволжье',
        'Зеленодольск',
        'Ишимбай',
        'КО, Обнинск - КО',
        'Каменск-Шахтинский',
        'Камышин',
        'Карачев',
        'Кинешма',
        'Кирово-Чепецк',
        'Кисловодск',
        'Клинцы',
        'Конаково',
        'Кондопога',
        'Кондрово',
        'Кореновск',
        'Коркино',
        'Костомукша',
        'Котельнич',
        'Красная Поляна',
        'Кузнецк',
        'Кунгур',
        'ЛО, Ломоносов',
        'ЛО, Луга',
        'ЛО, Никольское',
        'ЛО, Парголово',
        'ЛО, Петергоф',
        'ЛО, Тосно',
        'ЛО, Шлиссельбург',
        'Лебедянь',
        'Лысьва',
        'МО, Апрелевка',
        'МО, Боброво',
        'МО, Борисово',
        'МО, Вешки',
        'МО, Внуковское',
        'МО, Волоколамск',
        'МО, Воскресенск',
        'МО, Голицыно',
        'МО, Зарайск',
        'МО, Климовск',
        'МО, Коммунарка',
        'МО, Красково',
        'МО, Красноармейск',
        'МО, Краснозаводск',
        'МО, Лосино-Петровский',
        'МО, Луховицы',
        'МО, Марфино',
        'МО, Монино',
        'МО, Нахабино',
        'МО, Немчиновка',
        'МО, Обнинск',
        'МО, Протвино',
        'МО, Путилково',
        'МО, Рошаль',
        'МО, Руза',
        'МО, Сходня',
        'МО, Хотьково',
        'МО, Щербинка',
        'МО, Электрогорск',
        'МО, Электроугли',
        'МО, Юдино',
        'МО, Яхрома',
        'МО, д.Картмазово',
        'МО, д.Ликино',
        'Малоярославец',
        'Медногорск',
        'Мелеуз',
        'Миллерово',
        'Минусинск',
        'Михайловка',
        'Мичуринск',
        'Можга',
        'Мончегорск',
        'Нефтеюганск',
        'Новая Адыгея',
        'Новоалтайск',
        'Нововоронеж',
        'Новодвинск',
        'Новотроицк',
        'Новый Уренгой',
        'Оленегорск',
        'Остров',
        'Павловская',
        'Переславль-Залесский',
        'Печоры',
        'Ревда',
        'Ржев',
        'Родники',
        'Россошь',
        'Рубцовск',
        'Сальск',
        'Сарапул',
        'Северодвинск',
        'Семенов',
        'Семилуки',
        'Сокол',
        'Соль-Илецк',
        'Среднеуральск',
        'Старая Русса',
        'Старый Оскол',
        'Сухой лог',
        'Сысерть',
        'Торжок',
        'Троицк',
        'Туймазы',
        'Тутаев',
        'Углич',
        'Урюпинск',
        'Шуя',
        'Ялуторовск',
    ];

    private const REQUEST_DELAY_MIN_MS = 5000;
    private const REQUEST_DELAY_MAX_MS = 10000;

    private const USER_AGENTS = [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_2_1) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.1 Safari/605.1.15',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Edg/122.0.0.0',
    ];

    private const ACCEPT_LANGUAGES = [
        'ru-RU,ru;q=0.9,en-US;q=0.7,en;q=0.6',
        'ru,en;q=0.8,en-US;q=0.6',
        'ru-RU,ru;q=0.8,en;q=0.7',
    ];

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
        try {
            $cities = $this->fetchCitiesFromChunk();
            if (!empty($cities)) {
                $this->writeCitiesCache($cities);
                return $this->normalizeCities($cities);
            }
        } catch (\Throwable $e) {
            $this->warn('Не удалось загрузить список городов, пробую кэш: ' . $e->getMessage());
        }

        $cached = $this->loadCitiesFromCache();
        if (!empty($cached)) {
            $this->info('Использую список городов из кэша.');
            return $this->normalizeCities($cached);
        }

        $this->warn('Кэш городов отсутствует, использую встроенный список.');
        $fallback = $this->makeCityEntriesFromNames(self::DEFAULT_CITY_NAMES);
        $this->writeCitiesCache($fallback);
        return $this->normalizeCities($fallback);
    }

    private function normalizeCities(array $cities): array
    {
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

    private function fetchCitiesFromChunk(): array
    {
        $cookieJar = new CookieJar();

        $this->info('Загружаю страницу https://kuper.ru/rabota/velokurer ...');
        $this->sleepRandomDelay();
        $resp = $this->makeRequest($cookieJar)
            ->get('https://kuper.ru/rabota/velokurer');
        if (!$resp->ok()) {
            throw new \RuntimeException('Не удалось загрузить страницу велокурьера.');
        }

        $html = $resp->body();
        if (!preg_match_all('/\\/chunks\\/([A-Za-z0-9-]+\\.js)|\\/chunks\\/pages\\/(_app-[A-Za-z0-9-]+\\.js)|\\/rabota\\/(_next\\/static\\/chunks\\/pages\\/_app-[A-Za-z0-9-]+\\.js)/', $html, $matches)) {
            throw new \RuntimeException('Не найден ни один chunk-файл на странице.');
        }
        $files = array_filter(array_merge($matches[1], $matches[2], $matches[3]));
        $files = array_map(function (string $file): string {
            if (str_starts_with($file, '_next/')) {
                return '/rabota/' . $file;
            }
            return $file;
        }, $files);
        $this->info('chunk-файлов найдено: ' . count($files));

        $seen = [];
        foreach (array_unique($files) as $file) {
            $url = $this->resolveChunkUrl($file);
            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $this->info("Проверяю chunk {$url}");
            $this->sleepRandomDelay();
            $chunkResp = $this->makeRequest($cookieJar, 'https://kuper.ru/rabota/velokurer')
                ->get($url);
            if (!$chunkResp->ok()) {
                continue;
            }

            if ($cities = $this->extractCitiesFromChunk($chunkResp->body())) {
                $this->info("Список городов найден в {$url}");

                return $cities;
            }
            $this->info("Не удалось распарсить список городов в {$url}");
        }

        throw new \RuntimeException('Не удалось найти список городов в chunk-чанках.');
    }

    private function extractCitiesFromChunk(string $chunk): ?array
    {
        if ($cities = $this->extractCitiesFromOpObject($chunk)) {
            return $cities;
        }

        $pattern = '/(\w+)\s*=\s*JSON\.parse\s*\(\s*(["\'])(.*?)\2\s*\)/s';
        if (!preg_match_all($pattern, $chunk, $matches)) {
            return null;
        }
        foreach ($matches[3] as $raw) {

            if (strpos($raw, '"abakan"') === false) {
                continue;
            }
            $payload = stripcslashes($raw);
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
            dump('error');
        }

        return null;
    }

    private function extractCitiesFromOpObject(string $chunk): ?array
    {
        if (!preg_match('/op\s*=\s*\{(.*?)\}(?:\s*;|\s*,|\s*\))/s', $chunk, $match)) {
            return null;
        }

        $payload = $match[1];
        $pattern = '/(?:"([^"]+)"|([A-Za-zА-Яа-яЁё0-9 ,.\-–—()]+))\s*:\s*\[\s*[-+]?\d+(?:\.\d+)?,\s*[-+]?\d+(?:\.\d+)?\s*\]/u';
        if (!preg_match_all($pattern, $payload, $matches, PREG_SET_ORDER)) {
            return null;
        }

        $cities = [];
        foreach ($matches as $entry) {
            $name = trim($entry[1] !== '' ? $entry[1] : $entry[2]);
            if ($name === '') {
                continue;
            }
            $cities[$name] = ['name' => $name];
        }

        return $cities ?: null;
    }

    private function loadCitiesFromCache(): ?array
    {
        $path = storage_path(self::CITY_CACHE_PATH);
        if (!is_file($path)) {
            return null;
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function writeCitiesCache(array $cities): void
    {
        $path = storage_path(self::CITY_CACHE_PATH);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $payload = json_encode($cities, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($payload !== false) {
            file_put_contents($path, $payload);
        }
    }

    private function makeCityEntriesFromNames(array $names): array
    {
        $entries = [];
        foreach (array_unique($names) as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $entries[$name] = ['name' => $name];
        }

        return $entries;
    }

    private function makeRequest(CookieJar $cookieJar, ?string $referer = null): PendingRequest
    {
        return Http::timeout(20)
            ->withHeaders($this->makeHeaders($referer))
            ->withOptions(['cookies' => $cookieJar]);
    }

    private function makeHeaders(?string $referer = null): array
    {
        $headers = [
            'User-Agent' => self::USER_AGENTS[array_rand(self::USER_AGENTS)],
            'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Accept-Language' => self::ACCEPT_LANGUAGES[array_rand(self::ACCEPT_LANGUAGES)],
            'Accept-Encoding' => 'gzip, deflate, br',
            'Cache-Control' => 'no-cache',
            'Pragma' => 'no-cache',
            'Connection' => 'keep-alive',
            'DNT' => '1',
            'Upgrade-Insecure-Requests' => '1',
        ];

        if ($referer) {
            $headers['Referer'] = $referer;
        }

        return $headers;
    }

    private function sleepRandomDelay(): void
    {
        $delayMs = random_int(self::REQUEST_DELAY_MIN_MS, self::REQUEST_DELAY_MAX_MS);
        usleep($delayMs * 1000);
    }

    private function resolveChunkUrl(string $file): string
    {
        if (str_starts_with($file, 'http')) {
            return $file;
        }

        if (str_starts_with($file, '/')) {
            return 'https://kuper.ru' . $file;
        }

        return 'https://kuper.ru/rabota/_next/static/chunks/' . ltrim($file, '/');
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
