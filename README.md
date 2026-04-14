# Togul PHP SDK

PHP client for evaluating Togul feature flags with local TTL caching and fallback behavior.

## Install

```bash
composer require togul/php-sdk
```

## Usage

```php
<?php

use Togul\Config;
use Togul\FallbackMode;
use Togul\TogulClient;

$client = new TogulClient(new Config(
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
