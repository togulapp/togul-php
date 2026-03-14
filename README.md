# Nori PHP SDK

PHP client for evaluating Nori feature flags with local TTL caching and fallback behavior.

## Install

```bash
composer require nori/php-sdk
```

## Usage

```php
<?php

use Nori\Config;
use Nori\FallbackMode;
use Nori\NoriClient;

$client = new NoriClient(new Config(
    baseUrl: 'http://localhost:8080',
    environment: 'production',
    apiKey: 'your-environment-api-key',
    timeout: 5.0,
    cacheTtl: 30,
    fallbackMode: FallbackMode::FailClosed,
    retryCount: 2,
));

$enabled = $client->isEnabled('new-dashboard', [
    'user_id' => 'user-123',
    'country' => 'TR',
]);
```

## Notes

- `apiKey` must be an environment API key, not a user JWT.
- Requests are sent to `POST /api/v1/evaluate` with the `X-API-Key` header.
- The cache key includes the full evaluation context.
- The client retries `429` and `5xx`, but stops immediately on `401`/`403`/`404`.
