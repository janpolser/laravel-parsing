<?php

namespace App\Support;

final class FeedCountTolerance
{
    public static function allows(int $expected, int $actual): bool
    {
        if ($expected < 1 || $actual < 1) {
            return false;
        }

        return abs($expected - $actual) <= 10;
    }
}
