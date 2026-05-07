<?php

namespace App\Services\UniversalScraper\Exceptions;

use RuntimeException;

class ExternalRedirectException extends RuntimeException
{
    public function __construct(
        public readonly string $sourceUrl,
        public readonly string $finalUrl,
    ) {
        parent::__construct("External redirect from {$sourceUrl} to {$finalUrl}");
    }
}
