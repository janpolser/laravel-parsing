<?php

namespace App\Services\UniversalScraper\Exceptions;

use RuntimeException;
use Throwable;

class PageFetchException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
