<?php

declare(strict_types=1);

namespace Nori;

class Config
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $environment,
        public readonly string $apiKey = '',
        public readonly float $timeout = 5.0,
        public readonly int $cacheTtl = 30,
        public readonly FallbackMode $fallbackMode = FallbackMode::FailClosed,
        public readonly int $retryCount = 2,
    ) {}
}
