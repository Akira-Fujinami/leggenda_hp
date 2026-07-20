<?php

namespace App\Services\Analysis;

readonly class FetchResult
{
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public int $httpStatus,
        public string $body,
        public ?string $contentType,
        public int $durationMs,
        public int $redirectCount = 0,
    ) {
    }
}
