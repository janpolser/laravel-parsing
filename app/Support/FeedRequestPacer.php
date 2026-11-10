<?php

namespace App\Support;

use Closure;
use RuntimeException;

class FeedRequestPacer
{
    private array $groups = [];

    private Closure $clock;

    private Closure $sleep;

    private Closure $random;

    public function __construct(
        private ?string $directory = null,
        ?Closure $clock = null,
        ?Closure $sleep = null,
        ?Closure $random = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static fn (int $microseconds) => usleep($microseconds);
        $this->random = $random ?? static fn (): int => random_int(3_000_000, 6_000_000);
    }

    public function enter(string $command): void
    {
        $this->groups[] = self::groupForCommand($command);
    }

    public function leave(): void
    {
        array_pop($this->groups);
    }

    public function currentGroup(): ?string
    {
        return $this->groups === [] ? null : $this->groups[array_key_last($this->groups)];
    }

    public static function groupForCommand(string $command): ?string
    {
        return match (true) {
            str_starts_with($command, 'wb:') => 'wildberries',
            in_array($command, ['yandex:vacancy-yandex', 'app:vacancy-yandex'], true) => 'yandex',
            in_array($command, ['pyaterochka:vacancy-5ka', 'perekrestok:collect-vacancies'], true) => 'x5',
            $command === 'magnit:collect-vacancy-magnit' => 'magnit',
            $command === 'rzhd:collect-vacancies' => 'rzhd',
            $command === 'kuper:collect-vacancies' => 'kuper',
            $command === 'krasnoeibeloe:collect-vacancies' => 'krasnoeibeloe',
            $command === 'ozon:collect-vacancies' => 'ozon',
            $command === 'lenta:collect-vacancies' => 'lenta',
            $command === 'monetka:collect-vacancies' => 'monetka',
            $command === 'dixy:collect-vacancies' => 'dixy',
            $command === 'vkusvill:collect-vacancies' => 'vkusvill',
            $command === 'oez:collect-vacancies' => 'oez',
            default => null,
        };
    }

    public function waitForCurrentGroup(): void
    {
        $group = $this->currentGroup();
        if ($group !== null) {
            $this->wait($group);
        }
    }

    public function wait(string $group): void
    {
        if (! preg_match('/^[a-z0-9_-]+$/', $group)) {
            throw new RuntimeException('Некорректное имя группы ограничения запросов');
        }

        $directory = $this->directory ?? storage_path('app/private/feed-request-pacing');
        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог ограничения запросов');
        }

        $file = fopen($directory.'/'.$group.'.lock', 'c+');
        if ($file === false) {
            throw new RuntimeException('Не удалось открыть файл ограничения запросов');
        }

        try {
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('Не удалось заблокировать ограничитель запросов');
            }

            rewind($file);
            $previous = trim((string) stream_get_contents($file));
            if ($previous !== '' && is_numeric($previous)) {
                $now = ($this->clock)();
                $last = min((float) $previous, $now);
                $due = $last + ($this->random)() / 1_000_000;
                while (($remaining = $due - ($this->clock)()) > 0) {
                    ($this->sleep)((int) ceil($remaining * 1_000_000));
                }
            }

            rewind($file);
            if (! ftruncate($file, 0) || fwrite($file, sprintf('%.6F', ($this->clock)())) === false || ! fflush($file)) {
                throw new RuntimeException('Не удалось сохранить время последнего запроса');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
