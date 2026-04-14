<?php

declare(strict_types=1);

namespace Togul;

class Config
{
    private const DEFAULT_BASE_URL = 'https://api.togul.io';

    public function __construct(
        public readonly string $environment,
        public readonly string $apiKey = '',
        public readonly float $timeout = 5.0,
        public readonly int $cacheTtl = 30,
        public readonly FallbackMode $fallbackMode = FallbackMode::FailClosed,
        public readonly int $retryCount = 2,
        private readonly string $baseUrl = self::DEFAULT_BASE_URL,
    ) {}

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
