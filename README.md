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

## OpenFeature

`Togul\OpenFeature\TogulProvider` plugs Togul into the [OpenFeature](https://openfeature.dev) PHP SDK, so application code can depend on the vendor-neutral API instead of `TogulClient`.

```bash
composer require open-feature/sdk
```

```php
use OpenFeature\OpenFeatureAPI;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use Togul\Config;
use Togul\OpenFeature\TogulProvider;
use Togul\TogulClient;

$togul = new TogulClient(new Config(environment: 'production', apiKey: 'your-environment-api-key'));

$api = OpenFeatureAPI::getInstance();
$api->setProvider(new TogulProvider($togul));

$client = $api->getClient();
$context = new EvaluationContext('user-42', new Attributes(['country' => 'TR']));

$client->getBooleanValue('new-dashboard', false, $context);
$client->getStringValue('theme', 'light', $context);
$client->getIntegerValue('max-items', 10, $context);
$client->getObjectValue('limits', [], $context);
```

The provider only adapts `TogulClient::evaluate()`; caching, retries and SSE invalidation are unchanged.

| Togul | OpenFeature |
|---|---|
| `reason: rule_match` | `TARGETING_MATCH` |
| `reason: default` | `DEFAULT` |
| `enabled: false` | caller's default value, reason `DISABLED` |
| `404 evaluate.flag_not_found` | caller's default, `FLAG_NOT_FOUND` |
| value does not fit the requested type | caller's default, `TYPE_MISMATCH` |
| any other error | caller's default, `GENERAL` |

- The targeting key is sent as the `user_id` context attribute, the default `bucket_by` for percentage rules. Pass a second constructor argument to use another name. An explicit `user_id` attribute takes precedence.
- Togul's `number` type serves both `getIntegerValue` (whole numbers only) and `getFloatValue`.
- Togul rules compare strings, so attribute values are converted before sending: `true`/`false` for booleans, plain numbers for ints and floats, ATOM (`2026-01-02T03:04:05+00:00`) for `DateTime`, JSON for arrays. `null`, `NAN` and `INF` attributes are dropped.

## Notes

- `apiKey` must be an environment API key, not a user JWT.
- Requests are sent to `POST /api/v1/evaluate` with the `X-API-Key` header.
- The cache key includes the full evaluation context.
- The client retries `429` and `5xx`, but stops immediately on `401`/`403`/`404`.
