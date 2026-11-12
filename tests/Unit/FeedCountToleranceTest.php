<?php

use App\Support\FeedCountTolerance;

test('небольшое расхождение допускается, пустой или сильно неполный каталог нет', function () {
    expect(FeedCountTolerance::allows(5661, 5659))->toBeTrue()
        ->and(FeedCountTolerance::allows(1000, 990))->toBeTrue()
        ->and(FeedCountTolerance::allows(1000, 989))->toBeFalse()
        ->and(FeedCountTolerance::allows(10, 0))->toBeFalse()
        ->and(FeedCountTolerance::allows(0, 10))->toBeFalse();
});
