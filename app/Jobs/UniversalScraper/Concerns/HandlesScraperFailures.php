<?php

namespace App\Jobs\UniversalScraper\Concerns;

use App\Services\UniversalScraper\Exceptions\PageFetchException;
use App\Services\UniversalScraper\ScraperRepository;
use Throwable;

trait HandlesScraperFailures
{
    protected function handleSiteError(
        ScraperRepository $repository,
        string $siteKey,
        Throwable $exception,
        int $defaultIntervalHours,
    ): void {
        $status = $exception instanceof PageFetchException ? $exception->statusCode : null;

        if (in_array($status, [403, 404], true)) {
            $repository->setParseStatus($siteKey, 'unreachable');
            $repository->setFailure($siteKey, intervalHours: 24 * 7, httpStatus: $status, incrementFail: true);

            return;
        }

        $failCount = $repository->setFailure(
            $siteKey,
            intervalHours: $status === null ? 24 : $defaultIntervalHours,
            httpStatus: $status,
            incrementFail: true,
        );

        if ($failCount >= 3) {
            $repository->setParseStatus($siteKey, 'unreachable');
        }
    }
}
