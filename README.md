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
use Togul\TogulClient;

$client = new TogulClient(new Config(
    environment: 'production',
    apiKey: 'your-environment-api-key',
    timeout: 5.0,
    cacheTtl: 30,
    retryCount: 2,
));

$result = $client->evaluate('new-dashboard', [
    'user_id' => 'user-123',
    'country' => 'TR',
]);

var_dump($result->enabled);   // true
var_dump($result->valueType); // "string"
var_dump($result->value);     // "dark_mode"
var_dump($result->reason);    // "rule_match"
```

## EvaluateResult

`evaluate()` returns an `EvaluateResult` object:

```php
$result->flagKey;    // string  — flag identifier
$result->enabled;    // bool    — whether the flag is on
$result->valueType;  // string  — "boolean" | "string" | "number" | "json"
$result->value;      // mixed   — the resolved value
$result->reason;     // string  — e.g. "rule_match", "default"
```

## Notes

- `apiKey` must be an environment API key, not a user JWT.
- Requests are sent to `POST /api/v1/evaluate` with the `X-API-Key` header.
- The cache key includes the full evaluation context.
- The client retries `429` and `5xx`, but stops immediately on `401`/`403`/`404`.
