# Togul PHP SDK

PHP client for evaluating Togul feature flags with local TTL caching and fallback behavior.

## Install

```bash
composer require togul/php-sdk
```

## Usage

### Boolean flag (on/off)

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

### Multi-variant flags

Use typed convenience methods to read flag values beyond boolean:

```php
// String variant
$theme = $client->evaluateString('ui-theme', ['user_id' => 'user-123'], fallback: 'default');

// Number variant
$limit = $client->evaluateNumber('rate-limit', ['plan' => 'pro'], fallback: 100.0);

// Boolean variant
$flag = $client->evaluateBool('beta-feature', ['user_id' => 'user-123'], fallback: false);

// JSON variant (returns decoded value)
$config = $client->evaluateJson('feature-config', ['user_id' => 'user-123'], fallback: null);
```

### Full evaluation result

`evaluateResult()` returns an `EvaluateResult` object with all flag metadata and typed accessors:

```php
$result = $client->evaluateResult('checkout-flow', ['user_id' => 'user-123']);

$result->enabled;              // bool
$result->flagKey;              // string
$result->valueType;            // 'boolean' | 'string' | 'number' | 'json'
$result->reason;               // string

$result->boolValue(false);     // bool
$result->stringValue('');      // string
$result->numberValue(0.0);     // float
$result->jsonValue(null);      // mixed
```

## Notes

- `apiKey` must be an environment API key, not a user JWT.
- Requests are sent to `POST /api/v1/evaluate` with the `X-API-Key` header.
- The cache key includes the full evaluation context.
- The client retries `429` and `5xx`, but stops immediately on `401`/`403`/`404`.
- Typed accessors (`boolValue`, `stringValue`, etc.) return the fallback if the flag is disabled or the value type does not match.
