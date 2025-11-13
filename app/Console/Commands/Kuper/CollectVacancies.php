<?php

namespace App\Console\Commands\Kuper;

use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CollectVacancies extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
     protected $signature = 'app:collect-vacancies
        {--outfile=kuper_vacancies : Имя XLSX в storage/app (без .xlsx)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    private const CITIES = [
        "abakan" => ["name" => "Абакан", "declination" => "Абакане"],
        "adler" => ["name" => "Адлер", "declination" => "Адлере"],
        "azov" => ["name" => "Азов", "declination" => "Азове"],
        "aksai" => ["name" => "Аксай", "declination" => "Аксае"],
        "aleksandrov" => ["name" => "Александров", "declination" => "Александрове"],
        "aleksin" => ["name" => "Алексин", "declination" => "Алексине"],
        "almetevsk" => ["name" => "Альметьевск", "declination" => "Альметьевске"],
        "anadir" => ["name" => "Анадырь", "declination" => "Анадыре"],
        "anapa" => ["name" => "Анапа", "declination" => "Анапе"],
        "angarsk" => ["name" => "Ангарск", "declination" => "Ангарске"],
        "apatiti" => ["name" => "Апатиты", "declination" => "Апатитах"],
        "arzamas" => ["name" => "Арзамас", "declination" => "Арзамасе"],
        "armavir" => ["name" => "Армавир", "declination" => "Армавире"],
        "artem" => ["name" => "Артем", "declination" => "Артеме"],
        "arkhangelsk" => ["name" => "Архангельск", "declination" => "Архангельске"],
        "astrakhan" => ["name" => "Астрахань", "declination" => "Астрахани"],
        "achinsk" => ["name" => "Ачинск", "declination" => "Ачинске"],
        "balakovo" => ["name" => "Балаково", "declination" => "Балаково"],
        "balakhna" => ["name" => "Балахна", "declination" => "Балахне"],
        "baltiisk" => ["name" => "Балтийск", "declination" => "Балтийске"],
        "barnaul" => ["name" => "Барнаул", "declination" => "Барнауле"],
        "bataisk" => ["name" => "Батайск", "declination" => "Батайске"],
        "belgorod" => ["name" => "Белгород", "declination" => "Белгороде"],
        "beloretsk" => ["name" => "Белорецк", "declination" => "Белорецке"],
        "belorechensk" => [
            "name" => "Белореченск",
            "declination" => "Белореченске",
        ],
        "berdsk" => ["name" => "Бердск", "declination" => "Бердске"],
        "berezniki" => ["name" => "Березники", "declination" => "Березниках"],
        "berezovskii" => ["name" => "Березовский", "declination" => "Березовском"],
        "biisk" => ["name" => "Бийск", "declination" => "Бийске"],
        "birobidzhan" => ["name" => "Биробиджан", "declination" => "Биробиджане"],
        "birsk" => ["name" => "Бирск", "declination" => "Бирске"],
        "blagoveshchensk" => [
            "name" => "Благовещенск",
            "declination" => "Благовещенске",
        ],
        "blagodarnii" => ["name" => "Благодарный", "declination" => "Благодарном"],
        "bor" => ["name" => "Бор", "declination" => "Бору"],
        "borovichi" => ["name" => "Боровичи", "declination" => "Боровичах"],
        "bratsk" => ["name" => "Братск", "declination" => "Братске"],
        "bryansk" => ["name" => "Брянск", "declination" => "Брянске"],
        "bugulma" => ["name" => "Бугульма", "declination" => "Бугульме"],
        "buzuluk" => ["name" => "Бузулук", "declination" => "Бузулуке"],
        "velikie-luki" => [
            "name" => "Великие Луки",
            "declination" => "Великих Луках",
        ],
        "velikii-novgorod" => [
            "name" => "Великий Новгород",
            "declination" => "Великом Новгороде",
        ],
        "velikii-ustyug" => [
            "name" => "Великий Устюг",
            "declination" => "Великом Устюге",
        ],
        "verkhnerusskoe" => [
            "name" => "Верхнерусское",
            "declination" => "Верхнерусском",
        ],
        "verkhnyaya-pishma" => [
            "name" => "Верхняя Пышма",
            "declination" => "Верхней Пышме",
        ],
        "vichuga" => ["name" => "Вичуга", "declination" => "Вичуге"],
        "vladivostok" => ["name" => "Владивосток", "declination" => "Владивостоке"],
        "vladikavkaz" => ["name" => "Владикавказ", "declination" => "Владикавказе"],
        "vladimir" => ["name" => "Владимир", "declination" => "Владимире"],
        "volgograd" => ["name" => "Волгоград", "declination" => "Волгограде"],
        "volgodonsk" => ["name" => "Волгодонск", "declination" => "Волгодонске"],
        "volgorechensk" => [
            "name" => "Волгореченск",
            "declination" => "Волгореченске",
        ],
        "volzhsk" => ["name" => "Волжск", "declination" => "Волжске"],
        "volzhskii" => ["name" => "Волжский", "declination" => "Волжском"],
        "vologda" => ["name" => "Вологда", "declination" => "Вологде"],
        "voronezh" => ["name" => "Воронеж", "declination" => "Воронеже"],
        "votkinsk" => ["name" => "Воткинск", "declination" => "Воткинске"],
        "viksa" => ["name" => "Выкса", "declination" => "Выксе"],
        "vishnii-volochek" => [
            "name" => "Вышний Волочек",
            "declination" => "Вышнем Волочке",
        ],
        "vyatskie-polyani" => [
            "name" => "Вятские Поляны",
            "declination" => "Вятских Полянах",
        ],
        "gai" => ["name" => "Гай", "declination" => "Гае"],
        "gelendzhik" => ["name" => "Геленджик", "declination" => "Геленджике"],
        "georgievsk" => ["name" => "Георгиевск", "declination" => "Георгиевске"],
        "glazov" => ["name" => "Глазов", "declination" => "Глазове"],
        "gorno-altaisk" => [
            "name" => "Горно-Алтайск",
            "declination" => "Горно-Алтайске",
        ],
        "groznii" => ["name" => "Грозный", "declination" => "Грозном"],
        "gryazi" => ["name" => "Грязи", "declination" => "Грязях"],
        "gubkin" => ["name" => "Губкин", "declination" => "Губкине"],
        "gukovo" => ["name" => "Гуково", "declination" => "Гуково"],
        "gus-khrustalnii" => [
            "name" => "Гусь-Хрустальный",
            "declination" => "Гусь-Хрустальном",
        ],
        "derbent" => ["name" => "Дербент", "declination" => "Дербенте"],
        "dzerzhinsk" => ["name" => "Дзержинск", "declination" => "Дзержинске"],
        "divnogorsk" => ["name" => "Дивногорск", "declination" => "Дивногорске"],
        "dimitrovgrad" => [
            "name" => "Димитровград",
            "declination" => "Димитровграде",
        ],
        "donskoi" => ["name" => "Донской", "declination" => "Донском"],
        "yekaterinburg" => [
            "name" => "Екатеринбург",
            "declination" => "Екатеринбурге",
        ],
        "yelabuga" => ["name" => "Елабуга", "declination" => "Елабуге"],
        "yelets" => ["name" => "Елец", "declination" => "Ельце"],
        "yessentuki" => ["name" => "Ессентуки", "declination" => "Ессентуках"],
        "yefremov" => ["name" => "Ефремов", "declination" => "Ефремове"],
        "zheleznovodsk" => [
            "name" => "Железноводск",
            "declination" => "Железноводске",
        ],
        "zavodoukovsk" => [
            "name" => "Заводоуковск",
            "declination" => "Заводоуковске",
        ],
        "zavolzhe" => ["name" => "Заволжье", "declination" => "Заволжье"],
        "zelenogradsk" => [
            "name" => "Зеленоградск",
            "declination" => "Зеленоградске",
        ],
        "zelenodolsk" => [
            "name" => "Зеленодольск",
            "declination" => "Зеленодольске",
        ],
        "ivanovo" => ["name" => "Иваново", "declination" => "Иваново"],
        "izhevsk" => ["name" => "Ижевск", "declination" => "Ижевске"],
        "irkutsk" => ["name" => "Иркутск", "declination" => "Иркутске"],
        "ishim" => ["name" => "Ишим", "declination" => "Ишиме"],
        "ishimbai" => ["name" => "Ишимбай", "declination" => "Ишимбае"],
        "yoshkar-ola" => ["name" => "Йошкар-Ола", "declination" => "Йошкар-Оле"],
        "kk-zheleznogorsk" => [
            "name" => "КК, Железногорск",
            "declination" => "Железногорске",
            "region" => "(КК)",
        ],
        "ko-zheleznogorsk" => [
            "name" => "КО, Железногорск",
            "declination" => "Железногорске",
            "region" => "(КО)",
        ],
        "ko-obninsk" => [
            "name" => "КО, Обнинск - КО",
            "declination" => "Обнинске",
            "region" => "(КО)",
        ],
        "kazan" => ["name" => "Казань", "declination" => "Казани"],
        "kaliningrad" => ["name" => "Калининград", "declination" => "Калининграде"],
        "kaluga" => ["name" => "Калуга", "declination" => "Калуге"],
        "kamensk-uralskii" => [
            "name" => "Каменск-Уральский",
            "declination" => "Каменске-Уральском",
        ],
        "kamensk-shakhtinskii" => [
            "name" => "Каменск-Шахтинский",
            "declination" => "Каменске-Шахтинском",
        ],
        "kamishin" => ["name" => "Камышин", "declination" => "Камышине"],
        "kanash" => ["name" => "Канаш", "declination" => "Канаше"],
        "kansk" => ["name" => "Канск", "declination" => "Канске"],
        "karachev" => ["name" => "Карачев", "declination" => "Карачеве"],
        "kaspiisk" => ["name" => "Каспийск", "declination" => "Каспийске"],
        "kemerovo" => ["name" => "Кемерово", "declination" => "Кемерово"],
        "kizilyurt" => ["name" => "Кизилюрт", "declination" => "Кизилюрте"],
        "kimri" => ["name" => "Кимры", "declination" => "Кимрах"],
        "kineshma" => ["name" => "Кинешма", "declination" => "Кинешме"],
        "kirov" => ["name" => "Киров", "declination" => "Кирове"],
        "kirovo-chepetsk" => [
            "name" => "Кирово-Чепецк",
            "declination" => "Кирово-Чепецке",
        ],
        "kislovodsk" => ["name" => "Кисловодск", "declination" => "Кисловодске"],
        "klintsi" => ["name" => "Клинцы", "declination" => "Клинцах"],
        "kovrov" => ["name" => "Ковров", "declination" => "Коврове"],
        "komsomolsk-na-amure" => [
            "name" => "Комсомольск-на-Амуре",
            "declination" => "Комсомольске-на-Амуре",
        ],
        "konakovo" => ["name" => "Конаково", "declination" => "Конаково"],
        "kondopoga" => ["name" => "Кондопога", "declination" => "Кондопоге"],
        "kondrovo" => ["name" => "Кондрово", "declination" => "Кондрово"],
        "kopeisk" => ["name" => "Копейск", "declination" => "Копейске"],
        "korenovsk" => ["name" => "Кореновск", "declination" => "Кореновске"],
        "korkino" => ["name" => "Коркино", "declination" => "Коркино"],
        "kostomuksha" => ["name" => "Костомукша", "declination" => "Костомукше"],
        "kostroma" => ["name" => "Кострома", "declination" => "Костроме"],
        "kotelnich" => ["name" => "Котельнич", "declination" => "Котельниче"],
        "krasnaya-polyana" => [
            "name" => "Красная Поляна",
            "declination" => "Красной Поляне",
        ],
        "krasnodar" => ["name" => "Краснодар", "declination" => "Краснодаре"],
        "krasnokamsk" => ["name" => "Краснокамск", "declination" => "Краснокамске"],
        "krasnoyarsk" => ["name" => "Красноярск", "declination" => "Красноярске"],
        "kstovo" => ["name" => "Кстово", "declination" => "Кстово"],
        "kuznetsk" => ["name" => "Кузнецк", "declination" => "Кузнецке"],
        "kungur" => ["name" => "Кунгур", "declination" => "Кунгуре"],
        "kurgan" => ["name" => "Курган", "declination" => "Кургане"],
        "kursk" => ["name" => "Курск", "declination" => "Курске"],
        "kizil" => ["name" => "Кызыл", "declination" => "Кызыле"],
        "lo-volkhov" => ["name" => "ЛО, Волхов", "declination" => "Волхове"],
        "lo-vsevolozhsk" => [
            "name" => "ЛО, Всеволожск",
            "declination" => "Всеволожске",
        ],
        "lo-viborg" => ["name" => "ЛО, Выборг", "declination" => "Выборге"],
        "lo-gatchina" => ["name" => "ЛО, Гатчина", "declination" => "Гатчине"],
        "lo-kingisepp" => [
            "name" => "ЛО, Кингисепп",
            "declination" => "Кингисеппе",
        ],
        "lo-kirishi" => ["name" => "ЛО, Кириши", "declination" => "Киришах"],
        "lo-kirovsk" => ["name" => "ЛО, Кировск", "declination" => "Кировске"],
        "lo-kolpino" => ["name" => "ЛО, Колпино", "declination" => "Колпино"],
        "lo-krasnoe-selo" => [
            "name" => "ЛО, Красное Село",
            "declination" => "Красном Селе",
        ],
        "lo-krasnii-bor" => [
            "name" => "ЛО, Красный Бор",
            "declination" => "Красном Бору",
        ],
        "lo-kronshtadt" => [
            "name" => "ЛО, Кронштадт",
            "declination" => "Кронштадте",
        ],
        "lo-kudrovo" => ["name" => "ЛО, Кудрово", "declination" => "Кудрово"],
        "lo-lomonosov" => [
            "name" => "ЛО, Ломоносов",
            "declination" => "Ломоносове",
        ],
        "lo-luga" => ["name" => "ЛО, Луга", "declination" => "Луге"],
        "lo-murino" => ["name" => "ЛО, Мурино", "declination" => "Мурино"],
        "lo-nikolskoe" => [
            "name" => "ЛО, Никольское",
            "declination" => "Никольском",
        ],
        "lo-otradnoe" => [
            "name" => "ЛО, Отрадное",
            "declination" => "Отрадном",
            "region" => "(ЛО)",
        ],
        "lo-pavlovsk" => ["name" => "ЛО, Павловск", "declination" => "Павловске"],
        "lo-pargolovo" => ["name" => "ЛО, Парголово", "declination" => "Парголово"],
        "lo-petergof" => ["name" => "ЛО, Петергоф", "declination" => "Петергофе"],
        "lo-pushkin" => ["name" => "ЛО, Пушкин", "declination" => "Пушкине"],
        "lo-sertolovo" => ["name" => "ЛО, Сертолово", "declination" => "Сертолово"],
        "lo-sestroretsk" => [
            "name" => "ЛО, Сестрорецк",
            "declination" => "Сестрорецке",
        ],
        "lo-sosnovii-bor" => [
            "name" => "ЛО, Сосновый Бор",
            "declination" => "Сосновом Бору",
        ],
        "lo-tikhvin" => ["name" => "ЛО, Тихвин", "declination" => "Тихвине"],
        "lo-tosno" => ["name" => "ЛО, Тосно", "declination" => "Тосно"],
        "lo-shlisselburg" => [
            "name" => "ЛО, Шлиссельбург",
            "declination" => "Шлиссельбурге",
        ],
        "lebedyan" => ["name" => "Лебедянь", "declination" => "Лебедяни"],
        "leninogorsk" => ["name" => "Лениногорск", "declination" => "Лениногорске"],
        "leninsk-kuznetskii" => [
            "name" => "Ленинск-Кузнецкий",
            "declination" => "Ленинске-Кузнецком",
        ],
        "lesosibirsk" => ["name" => "Лесосибирск", "declination" => "Лесосибирске"],
        "lipetsk" => ["name" => "Липецк", "declination" => "Липецке"],
        "liski" => ["name" => "Лиски", "declination" => "Лисках"],
        "lukhovka" => ["name" => "Луховка", "declination" => "Луховке"],
        "lisva" => ["name" => "Лысьва", "declination" => "Лысьве"],
        "mo-andreevka" => ["name" => "МО, Андреевка", "declination" => "Андреевке"],
        "mo-aparinki" => ["name" => "МО, Апаринки", "declination" => "Апаринках"],
        "mo-aprelevka" => ["name" => "МО, Апрелевка", "declination" => "Апрелевке"],
        "mo-balashikha" => ["name" => "МО, Балашиха", "declination" => "Балашихе"],
        "mo-bobrovo" => ["name" => "МО, Боброво", "declination" => "Боброво"],
        "mo-borisovo" => ["name" => "МО, Борисово", "declination" => "Борисово"],
        "mo-bronnitsi" => ["name" => "МО, Бронницы", "declination" => "Бронницах"],
        "mo-veshki" => ["name" => "МО, Вешки", "declination" => "Вешках"],
        "mo-vidnoe" => ["name" => "МО, Видное", "declination" => "Видном"],
        "mo-vnukovskoe" => [
            "name" => "МО, Внуковское",
            "declination" => "Внуковском",
        ],
        "mo-volokolamsk" => [
            "name" => "МО, Волоколамск",
            "declination" => "Волоколамске",
        ],
        "mo-voskresensk" => [
            "name" => "МО, Воскресенск",
            "declination" => "Воскресенске",
        ],
        "mo-visokovsk" => [
            "name" => "МО, Высоковск",
            "declination" => "Высоковске",
        ],
        "mo-golitsino" => ["name" => "МО, Голицыно", "declination" => "Голицыно"],
        "mo-dedovsk" => ["name" => "МО, Дедовск", "declination" => "Дедовске"],
        "mo-dzerzhinskii" => [
            "name" => "МО, Дзержинский",
            "declination" => "Дзержинском",
        ],
        "mo-dmitrov" => ["name" => "МО, Дмитров", "declination" => "Дмитрове"],
        "mo-dmitrovskoe" => [
            "name" => "МО, Дмитровское",
            "declination" => "Дмитровском",
        ],
        "mo-dolgoprudnii" => [
            "name" => "МО, Долгопрудный",
            "declination" => "Долгопрудном",
        ],
        "mo-domodedovo" => [
            "name" => "МО, Домодедово",
            "declination" => "Домодедово",
        ],
        "mo-dubna" => ["name" => "МО, Дубна", "declination" => "Дубне"],
        "mo-egorevsk" => ["name" => "МО, Егорьевск", "declination" => "Егорьевске"],
        "mo-zheleznodorozhnii" => [
            "name" => "МО, Железнодорожный",
            "declination" => "Железнодорожном",
        ],
        "mo-zhukovskii" => [
            "name" => "МО, Жуковский",
            "declination" => "Жуковском",
        ],
        "mo-zaraisk" => ["name" => "МО, Зарайск", "declination" => "Зарайске"],
        "mo-zvenigorod" => [
            "name" => "МО, Звенигород",
            "declination" => "Звенигороде",
        ],
        "mo-zelenograd" => [
            "name" => "МО, Зеленоград",
            "declination" => "Зеленограде",
        ],
        "mo-ivanteevka" => [
            "name" => "МО, Ивантеевка",
            "declination" => "Ивантеевке",
        ],
        "mo-istra" => ["name" => "МО, Истра", "declination" => "Истре"],
        "mo-kashira" => ["name" => "МО, Кашира", "declination" => "Кашире"],
        "mo-klimovsk" => ["name" => "МО, Климовск", "declination" => "Климовске"],
        "mo-klin" => ["name" => "МО, Клин", "declination" => "Клине"],
        "mo-kolomna" => ["name" => "МО, Коломна", "declination" => "Коломне"],
        "mo-kommunarka" => [
            "name" => "МО, Коммунарка",
            "declination" => "Коммунарке",
        ],
        "mo-korolev" => ["name" => "МО, Королев", "declination" => "Королеве"],
        "mo-kotelniki" => [
            "name" => "МО, Котельники",
            "declination" => "Котельниках",
        ],
        "mo-kraskovo" => ["name" => "МО, Красково", "declination" => "Красково"],
        "mo-krasnoarmeisk" => [
            "name" => "МО, Красноармейск",
            "declination" => "Красноармейске",
        ],
        "mo-krasnogorsk" => [
            "name" => "МО, Красногорск",
            "declination" => "Красногорске",
        ],
        "mo-krasnozavodsk" => [
            "name" => "МО, Краснозаводск",
            "declination" => "Краснозаводске",
        ],
        "mo-lobnya" => ["name" => "МО, Лобня", "declination" => "Лобне"],
        "mo-lopatino" => ["name" => "МО, Лопатино", "declination" => "Лопатино"],
        "mo-losino-petrovskii" => [
            "name" => "МО, Лосино-Петровский",
            "declination" => "Лосино-Петровском",
        ],
        "mo-lukhovitsi" => ["name" => "МО, Луховицы", "declination" => "Луховицах"],
        "mo-litkarino" => ["name" => "МО, Лыткарино", "declination" => "Лыткарино"],
        "mo-lyubertsi" => ["name" => "МО, Люберцы", "declination" => "Люберцах"],
        "mo-marfino" => ["name" => "МО, Марфино", "declination" => "Марфино"],
        "mo-mozhaisk" => ["name" => "МО, Можайск", "declination" => "Можайске"],
        "mo-monino" => ["name" => "МО, Монино", "declination" => "Монино"],
        "mo-moskovskii" => [
            "name" => "МО, Московский",
            "declination" => "Московском",
        ],
        "mo-mitishchi" => ["name" => "МО, Мытищи", "declination" => "Мытищах"],
        "mo-naro-fominsk" => [
            "name" => "МО, Наро-Фоминск",
            "declination" => "Наро-Фоминске",
        ],
        "mo-nakhabino" => ["name" => "МО, Нахабино", "declination" => "Нахабино"],
        "mo-nemchinovka" => [
            "name" => "МО, Немчиновка",
            "declination" => "Немчиновке",
        ],
        "mo-novodrozhzhino" => [
            "name" => "МО, Новодрожжино",
            "declination" => "Новодрожжино",
        ],
        "mo-novie-psarki" => [
            "name" => "МО, Новые Псарьки",
            "declination" => "Новых Псарьках",
        ],
        "mo-noginsk" => ["name" => "МО, Ногинск", "declination" => "Ногинске"],
        "mo-obninsk" => [
            "name" => "МО, Обнинск",
            "declination" => "Обнинске",
            "region" => "(МО)",
        ],
        "mo-odintsovo" => ["name" => "МО, Одинцово", "declination" => "Одинцово"],
        "mo-ozeri" => ["name" => "МО, Озеры", "declination" => "Озерах"],
        "mo-orekhovo-zuevo" => [
            "name" => "МО, Орехово-Зуево",
            "declination" => "Орехово-Зуево",
        ],
        "mo-otradnoe" => [
            "name" => "МО, Отрадное",
            "declination" => "Отрадном",
            "region" => "(МО)",
        ],
        "mo-pavlino" => ["name" => "МО, Павлино", "declination" => "Павлино"],
        "mo-pavlovskii-posad" => [
            "name" => "МО, Павловский посад",
            "declination" => "Павловском посаде",
        ],
        "mo-podolsk" => ["name" => "МО, Подольск", "declination" => "Подольске"],
        "mo-podyachevo" => [
            "name" => "МО, Подъячево",
            "declination" => "Подъячево",
        ],
        "mo-protvino" => ["name" => "МО, Протвино", "declination" => "Протвино"],
        "mo-putilkovo" => ["name" => "МО, Путилково", "declination" => "Путилково"],
        "mo-pushkino" => ["name" => "МО, Пушкино", "declination" => "Пушкино"],
        "mo-pushchino" => ["name" => "МО, Пущино", "declination" => "Пущино"],
        "mo-ramenskoe" => ["name" => "МО, Раменское", "declination" => "Раменском"],
        "mo-reutov" => ["name" => "МО, Реутов", "declination" => "Реутове"],
        "mo-roshal" => ["name" => "МО, Рошаль", "declination" => "Рошале"],
        "mo-ruza" => ["name" => "МО, Руза", "declination" => "Рузе"],
        "mo-sergiev-posad" => [
            "name" => "МО, Сергиев Посад",
            "declination" => "Сергиевом Посаде",
        ],
        "mo-serpukhov" => ["name" => "МО, Серпухов", "declination" => "Серпухове"],
        "mo-solnechnogorsk" => [
            "name" => "МО, Солнечногорск",
            "declination" => "Солнечногорске",
        ],
        "mo-sosni" => ["name" => "МО, Сосны", "declination" => "Соснах"],
        "mo-stupino" => ["name" => "МО, Ступино", "declination" => "Ступино"],
        "mo-skhodnya" => ["name" => "МО, Сходня", "declination" => "Сходне"],
        "mo-tomilino" => ["name" => "МО, Томилино", "declination" => "Томилино"],
        "mo-troitsk" => [
            "name" => "МО, Троицк",
            "declination" => "Троицке",
            "region" => "(МО)",
        ],
        "mo-fryazino" => ["name" => "МО, Фрязино", "declination" => "Фрязино"],
        "mo-khimki" => ["name" => "МО, Химки", "declination" => "Химках"],
        "mo-khotkovo" => ["name" => "МО, Хотьково", "declination" => "Хотьково"],
        "mo-chernaya-gryaz" => [
            "name" => "МО, Черная Грязь",
            "declination" => "Черной Грязи",
        ],
        "mo-chernoe" => ["name" => "МО, Черное", "declination" => "Черном"],
        "mo-chekhov" => ["name" => "МО, Чехов", "declination" => "Чехове"],
        "mo-shatura" => ["name" => "МО, Шатура", "declination" => "Шатуре"],
        "mo-sholokhovo" => ["name" => "МО, Шолохово", "declination" => "Шолохово"],
        "mo-shchelkovo" => ["name" => "МО, Щелково", "declination" => "Щелково"],
        "mo-shcherbinka" => ["name" => "МО, Щербинка", "declination" => "Щербинке"],
        "mo-elektrogorsk" => [
            "name" => "МО, Электрогорск",
            "declination" => "Электрогорске",
        ],
        "mo-elektrostal" => [
            "name" => "МО, Электросталь",
            "declination" => "Электростале",
        ],
        "mo-elektrougli" => [
            "name" => "МО, Электроугли",
            "declination" => "Электроуглях",
        ],
        "mo-yudino" => ["name" => "МО, Юдино", "declination" => "Юдино"],
        "mo-yakhroma" => ["name" => "МО, Яхрома", "declination" => "Яхроме"],
        "mo-d.kartmazovo" => [
            "name" => "МО, д.Картмазово",
            "declination" => "д.Картмазово",
        ],
        "mo-d.likino" => ["name" => "МО, д.Ликино", "declination" => "д.Ликино"],
        "magadan" => ["name" => "Магадан", "declination" => "Магадане"],
        "magas" => ["name" => "Магас", "declination" => "Магасе"],
        "magnitogorsk" => [
            "name" => "Магнитогорск",
            "declination" => "Магнитогорске",
        ],
        "maikop" => ["name" => "Майкоп", "declination" => "Майкопе"],
        "maloyaroslavets" => [
            "name" => "Малоярославец",
            "declination" => "Малоярославце",
        ],
        "makhachkala" => ["name" => "Махачкала", "declination" => "Махачкале"],
        "mednogorsk" => ["name" => "Медногорск", "declination" => "Медногорске"],
        "meleuz" => ["name" => "Мелеуз", "declination" => "Мелеузе"],
        "miass" => ["name" => "Миасс", "declination" => "Миассе"],
        "millerovo" => ["name" => "Миллерово", "declination" => "Миллерово"],
        "mineralnie-vodi" => [
            "name" => "Минеральные Воды",
            "declination" => "Минеральных Водах",
        ],
        "minusinsk" => ["name" => "Минусинск", "declination" => "Минусинске"],
        "mikhailovka" => ["name" => "Михайловка", "declination" => "Михайловке"],
        "mikhailovsk" => ["name" => "Михайловск", "declination" => "Михайловске"],
        "michurinsk" => ["name" => "Мичуринск", "declination" => "Мичуринске"],
        "mozhga" => ["name" => "Можга", "declination" => "Можге"],
        "monchegorsk" => ["name" => "Мончегорск", "declination" => "Мончегорске"],
        "moskva" => ["name" => "Москва", "declination" => "Москве"],
        "murmansk" => ["name" => "Мурманск", "declination" => "Мурманске"],
        "murom" => ["name" => "Муром", "declination" => "Муроме"],
        "naberezhnie-chelni" => [
            "name" => "Набережные Челны",
            "declination" => "Набережных Челнах",
        ],
        "nazran" => ["name" => "Назрань", "declination" => "Назрани"],
        "nalchik" => ["name" => "Нальчик", "declination" => "Нальчике"],
        "naryan-mar" => ["name" => "Нарьян-Мар", "declination" => "Нарьян-Маре"],
        "nakhodka" => ["name" => "Находка", "declination" => "Находке"],
        "nevinnomissk" => [
            "name" => "Невинномысск",
            "declination" => "Невинномысске",
        ],
        "neftekamsk" => ["name" => "Нефтекамск", "declination" => "Нефтекамске"],
        "nefteyugansk" => [
            "name" => "Нефтеюганск",
            "declination" => "Нефтеюганске",
        ],
        "nizhnevartovsk" => [
            "name" => "Нижневартовск",
            "declination" => "Нижневартовске",
        ],
        "nizhnekamsk" => ["name" => "Нижнекамск", "declination" => "Нижнекамске"],
        "nizhnii-novgorod" => [
            "name" => "Нижний Новгород",
            "declination" => "Нижнем Новгороде",
        ],
        "nizhnii-tagil" => [
            "name" => "Нижний Тагил",
            "declination" => "Нижнем Тагиле",
        ],
        "novaya-adigeya" => [
            "name" => "Новая Адыгея",
            "declination" => "Новой Адыгее",
        ],
        "novoaltaisk" => ["name" => "Новоалтайск", "declination" => "Новоалтайске"],
        "novovoronezh" => [
            "name" => "Нововоронеж",
            "declination" => "Нововоронеже",
        ],
        "novodvinsk" => ["name" => "Новодвинск", "declination" => "Новодвинске"],
        "novokuznetsk" => [
            "name" => "Новокузнецк",
            "declination" => "Новокузнецке",
        ],
        "novokuibishevsk" => [
            "name" => "Новокуйбышевск",
            "declination" => "Новокуйбышевске",
        ],
        "novomoskovsk" => [
            "name" => "Новомосковск",
            "declination" => "Новомосковске",
        ],
        "novorossiisk" => [
            "name" => "Новороссийск",
            "declination" => "Новороссийске",
        ],
        "novosibirsk" => ["name" => "Новосибирск", "declination" => "Новосибирске"],
        "novotroitsk" => ["name" => "Новотроицк", "declination" => "Новотроицке"],
        "novocheboksarsk" => [
            "name" => "Новочебоксарск",
            "declination" => "Новочебоксарске",
        ],
        "novocherkassk" => [
            "name" => "Новочеркасск",
            "declination" => "Новочеркасске",
        ],
        "novoshakhtinsk" => [
            "name" => "Новошахтинск",
            "declination" => "Новошахтинске",
        ],
        "novii-urengoi" => [
            "name" => "Новый Уренгой",
            "declination" => "Новом Уренгое",
        ],
        "noginskii-raion" => [
            "name" => "Ногинский район",
            "declination" => "Ногинском районе",
        ],
        "noyabrsk" => ["name" => "Ноябрьск", "declination" => "Ноябрьске"],
        "ob" => ["name" => "Обь", "declination" => "Оби"],
        "oktyabrskii" => ["name" => "Октябрьский", "declination" => "Октябрьском"],
        "olenegorsk" => ["name" => "Оленегорск", "declination" => "Оленегорске"],
        "omsk" => ["name" => "Омск", "declination" => "Омске"],
        "orel" => ["name" => "Орел", "declination" => "Орле"],
        "orenburg" => ["name" => "Оренбург", "declination" => "Оренбурге"],
        "orsk" => ["name" => "Орск", "declination" => "Орске"],
        "ostrov" => ["name" => "Остров", "declination" => "Острове"],
        "pavlovo" => ["name" => "Павлово", "declination" => "Павлово"],
        "pavlovskaya" => ["name" => "Павловская", "declination" => "Павловской"],
        "penza" => ["name" => "Пенза", "declination" => "Пензе"],
        "pervouralsk" => [
            "name" => "Первоуральск",
            "declination" => "Первоуральске",
        ],
        "pereslavl-zalesskii" => [
            "name" => "Переславль-Залесский",
            "declination" => "Переславле-Залесском",
        ],
        "perm" => ["name" => "Пермь", "declination" => "Перми"],
        "petrozavodsk" => [
            "name" => "Петрозаводск",
            "declination" => "Петрозаводске",
        ],
        "petropavlovsk-kamchatskii" => [
            "name" => "Петропавловск-Камчатский",
            "declination" => "Петропавловске-Камчатском",
        ],
        "pechori" => ["name" => "Печоры", "declination" => "Печорах"],
        "prokopevsk" => ["name" => "Прокопьевск", "declination" => "Прокопьевске"],
        "pskov" => ["name" => "Псков", "declination" => "Пскове"],
        "pyatigorsk" => ["name" => "Пятигорск", "declination" => "Пятигорске"],
        "revda" => ["name" => "Ревда", "declination" => "Ревде"],
        "rzhev" => ["name" => "Ржев", "declination" => "Ржеве"],
        "rodniki" => ["name" => "Родники", "declination" => "Родниках"],
        "rossosh" => ["name" => "Россошь", "declination" => "Россоши"],
        "rostov-velikii" => [
            "name" => "Ростов Великий",
            "declination" => "Ростове Великом",
        ],
        "rostov-na-donu" => [
            "name" => "Ростов-на-Дону",
            "declination" => "Ростове-на-Дону",
        ],
        "rubtsovsk" => ["name" => "Рубцовск", "declination" => "Рубцовске"],
        "ruzaevka" => ["name" => "Рузаевка", "declination" => "Рузаевке"],
        "ribinsk" => ["name" => "Рыбинск", "declination" => "Рыбинске"],
        "ryazan" => ["name" => "Рязань", "declination" => "Рязани"],
        "salavat" => ["name" => "Салават", "declination" => "Салавате"],
        "salsk" => ["name" => "Сальск", "declination" => "Сальске"],
        "samara" => ["name" => "Самара", "declination" => "Самаре"],
        "spb" => ["name" => "Санкт-Петербург", "declination" => "Санкт-Петербурге"],
        "saransk" => ["name" => "Саранск", "declination" => "Саранске"],
        "sarapul" => ["name" => "Сарапул", "declination" => "Сарапуле"],
        "saratov" => ["name" => "Саратов", "declination" => "Саратове"],
        "sarov" => ["name" => "Саров", "declination" => "Сарове"],
        "sayanogorsk" => ["name" => "Саяногорск", "declination" => "Саяногорске"],
        "svetlogorsk" => ["name" => "Светлогорск", "declination" => "Светлогорске"],
        "severodvinsk" => [
            "name" => "Северодвинск",
            "declination" => "Северодвинске",
        ],
        "seversk" => ["name" => "Северск", "declination" => "Северске"],
        "semenov" => ["name" => "Семенов", "declination" => "Семенове"],
        "semiluki" => ["name" => "Семилуки", "declination" => "Семилуках"],
        "serov" => ["name" => "Серов", "declination" => "Серове"],
        "sibai" => ["name" => "Сибай", "declination" => "Сибае"],
        "smolensk" => ["name" => "Смоленск", "declination" => "Смоленске"],
        "sokol" => ["name" => "Сокол", "declination" => "Соколе"],
        "solikamsk" => ["name" => "Соликамск", "declination" => "Соликамске"],
        "sol-iletsk" => ["name" => "Соль-Илецк", "declination" => "Соль-Илецке"],
        "sosnovoborsk" => [
            "name" => "Сосновоборск",
            "declination" => "Сосновоборске",
        ],
        "sochi" => ["name" => "Сочи", "declination" => "Сочи"],
        "sredneuralsk" => [
            "name" => "Среднеуральск",
            "declination" => "Среднеуральске",
        ],
        "stavropol" => ["name" => "Ставрополь", "declination" => "Ставрополе"],
        "staraya-russa" => [
            "name" => "Старая Русса",
            "declination" => "Старой Руссе",
        ],
        "starii-oskol" => [
            "name" => "Старый Оскол",
            "declination" => "Старом Осколе",
        ],
        "sterlitamak" => ["name" => "Стерлитамак", "declination" => "Стерлитамаке"],
        "surgut" => ["name" => "Сургут", "declination" => "Сургуте"],
        "sukhoi-log" => ["name" => "Сухой Лог", "declination" => "Сухом Логе"],
        "sizran" => ["name" => "Сызрань", "declination" => "Сызрани"],
        "siktivkar" => ["name" => "Сыктывкар", "declination" => "Сыктывкаре"],
        "sisert" => ["name" => "Сысерть", "declination" => "Сысерти"],
        "taganrog" => ["name" => "Таганрог", "declination" => "Таганроге"],
        "tambov" => ["name" => "Тамбов", "declination" => "Тамбове"],
        "tver" => ["name" => "Тверь", "declination" => "Твери"],
        "temryuk" => ["name" => "Темрюк", "declination" => "Темрюке"],
        "timashevsk" => ["name" => "Тимашевск", "declination" => "Тимашевске"],
        "tobolsk" => ["name" => "Тобольск", "declination" => "Тобольске"],
        "tolyatti" => ["name" => "Тольятти", "declination" => "Тольятти"],
        "tomsk" => ["name" => "Томск", "declination" => "Томске"],
        "torzhok" => ["name" => "Торжок", "declination" => "Торжке"],
        "troitsk" => [
            "name" => "Троицк",
            "declination" => "Троицке",
            "region" => "(ЧО)",
        ],
        "tuapse" => ["name" => "Туапсе", "declination" => "Туапсе"],
        "tuimazi" => ["name" => "Туймазы", "declination" => "Туймазах"],
        "tula" => ["name" => "Тула", "declination" => "Туле"],
        "tutaev" => ["name" => "Тутаев", "declination" => "Тутаеве"],
        "tyumen" => ["name" => "Тюмень", "declination" => "Тюмени"],
        "uglich" => ["name" => "Углич", "declination" => "Угличе"],
        "uzlovaya" => ["name" => "Узловая", "declination" => "Узловой"],
        "ulan-ude" => ["name" => "Улан-Удэ", "declination" => "Улан-Удэ"],
        "ulyanovsk" => ["name" => "Ульяновск", "declination" => "Ульяновске"],
        "uryupinsk" => ["name" => "Урюпинск", "declination" => "Урюпинске"],
        "ussuriisk" => ["name" => "Уссурийск", "declination" => "Уссурийске"],
        "ufa" => ["name" => "Уфа", "declination" => "Уфе"],
        "khabarovsk" => ["name" => "Хабаровск", "declination" => "Хабаровске"],
        "khanti-mansiisk" => [
            "name" => "Ханты-Мансийск",
            "declination" => "Ханты-Мансийске",
        ],
        "chaikovskii" => ["name" => "Чайковский", "declination" => "Чайковском"],
        "chapaevsk" => ["name" => "Чапаевск", "declination" => "Чапаевске"],
        "cheboksari" => ["name" => "Чебоксары", "declination" => "Чебоксарах"],
        "chelyabinsk" => ["name" => "Челябинск", "declination" => "Челябинске"],
        "cherepovets" => ["name" => "Череповец", "declination" => "Череповце"],
        "cherkessk" => ["name" => "Черкесск", "declination" => "Черкесске"],
        "chernogorsk" => ["name" => "Черногорск", "declination" => "Черногорске"],
        "chistopol" => ["name" => "Чистополь", "declination" => "Чистополе"],
        "chita" => ["name" => "Чита", "declination" => "Чите"],
        "shakhti" => ["name" => "Шахты", "declination" => "Шахтах"],
        "shuya" => ["name" => "Шуя", "declination" => "Шуе"],
        "shchekino" => ["name" => "Щекино", "declination" => "Щекино"],
        "elista" => ["name" => "Элиста", "declination" => "Элисте"],
        "engels" => ["name" => "Энгельс", "declination" => "Энгельсе"],
        "yuzhno-sakhalinsk" => [
            "name" => "Южно-Сахалинск",
            "declination" => "Южно-Сахалинске",
        ],
        "yurga" => ["name" => "Юрга", "declination" => "Юрге"],
        "yakutsk" => ["name" => "Якутск", "declination" => "Якутске"],
        "yalutorovsk" => ["name" => "Ялуторовск", "declination" => "Ялуторовске"],
        "yaroslavl" => ["name" => "Ярославль", "declination" => "Ярославле"],
    ];

        private const COLUMN_SCHEMA = [
        'city_slug'        => 'Город (slug)',
        'city_name'        => 'Город',
        'city_declination' => 'Город (П.п.)',
        'city_region'      => 'Регион (если есть)',
        'vacancy_key'      => 'Код вакансии',
        'vacancy_title'    => 'Название вакансии',
        'vacancy_url'      => 'Ссылка',
        'salary_from'      => 'Зарплата от (мес)',
        'salary_to'        => 'Зарплата до (мес)',
        'salary_per_day'   => 'Заработок в день',
        'salary_text'      => 'Зарплата (текст)',
        'description'      => 'Описание',
    ];

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

    /**
     * Execute the console command.
     */
    public function handle()
    {
         $outfile = (string) $this->option('outfile');
        $outPath = storage_path('app/' . $outfile . '.xlsx');

        $this->info('Генерирую вакансии Купер по городам...');

        $rows = [];

        foreach (self::CITIES as $slug => $city) {
            $name       = $city['name'] ?? '';
            $decl       = $city['declination'] ?? null;
            $region     = $city['region'] ?? null;

            foreach (self::VACANCIES as $vac) {
                $rows[] = [
                    'city_slug'        => $slug,
                    'city_name'        => $name,
                    'city_declination' => $decl,
                    'city_region'      => $region,
                    'vacancy_key'      => $vac['key'],
                    'vacancy_title'    => $vac['title'],
                    'vacancy_url'      => $vac['url'],
                    'salary_from'      => $vac['salary_from'],
                    'salary_to'        => $vac['salary_to'],
                    'salary_per_day'   => $vac['salary_per_day'],
                    'salary_text'      => $this->makeSalaryText(
                        $vac['salary_from'],
                        $vac['salary_to'],
                        $vac['salary_per_day']
                    ),
                    'description'      => $vac['description'],
                ];
            }
        }

        if (empty($rows)) {
            $this->warn('Нет данных для записи.');
            return self::SUCCESS;
        }

        $this->writeXlsx($rows, $outPath);

        $this->info("Готово: {$outPath}");
        $this->info('Городов: ' . count(self::CITIES) . ', вакансий на город: ' . count(self::VACANCIES) .
            ', всего строк: ' . count($rows));

        return self::SUCCESS;
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

    private function writeXlsx(array $rows, string $outPath): void
    {
        $columnKeys = array_keys(self::COLUMN_SCHEMA);
        $headers    = array_values(self::COLUMN_SCHEMA);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Kuper Vacancies');

        // Заголовки
        $col = 1;
        foreach ($headers as $h) {
            $cell = Coordinate::stringFromColumnIndex($col) . '1';
            $sheet->setCellValue($cell, $h);
            $col++;
        }

        // Данные
        $rowIdx = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach ($columnKeys as $key) {
                $cell = Coordinate::stringFromColumnIndex($col) . $rowIdx;
                $sheet->setCellValue($cell, $row[$key] ?? null);
                $col++;
            }
            $rowIdx++;
        }

        // Автоширина колонок
        foreach (range(1, count($headers)) as $c) {
            $sheet->getColumnDimensionByColumn($c)->setAutoSize(true);
        }

        (new Xlsx($spreadsheet))->save($outPath);
    }
}
