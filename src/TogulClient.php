<?php

declare(strict_types=1);

namespace Togul;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;

class TogulClient
{
    private Client $http;
    private Cache $cache;
    private ?TogulStreamClient $streamClient = null;

    public function __construct(
        private readonly Config $config,
    ) {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->config->apiKey !== '') {
            $headers['X-API-Key'] = $this->config->apiKey;
        }

        $this->http = new Client([
            'base_uri' => rtrim($this->config->getBaseUrl(), '/'),
            'timeout' => $this->config->timeout,
            'headers' => $headers,
        ]);

        $this->cache = new Cache($this->config->cacheTtl);
    }

    /**
     * Evaluate a feature flag.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     * @return bool Whether the flag is enabled for the given context
     */
    public function isEnabled(string $key, array $context = []): bool
    {
        try {
            return $this->evaluateResult($key, $context)->enabled;
        } catch (\Throwable) {
            return match ($this->config->fallbackMode) {
                FallbackMode::FailOpen => true,
                FallbackMode::FailClosed => false,
            };
        }
    }

    /**
     * Evaluate a feature flag and return the full result with typed value accessors.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     */
    public function evaluateResult(string $key, array $context = []): EvaluateResult
    {
        $cacheKey = $this->buildCacheKey($key, $context);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $result = $this->evaluate($key, $context);
        $this->cache->set($cacheKey, $result);
        return $result;
    }

    /**
     * Evaluate a boolean flag.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     * @param bool $fallback Value to return on error or type mismatch
     */
    public function evaluateBool(string $key, array $context = [], bool $fallback = false): bool
    {
        try {
            return $this->evaluateResult($key, $context)->boolValue($fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Evaluate a string flag.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     * @param string $fallback Value to return on error or type mismatch
     */
    public function evaluateString(string $key, array $context = [], string $fallback = ''): string
    {
        try {
            return $this->evaluateResult($key, $context)->stringValue($fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Evaluate a number flag.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     * @param float $fallback Value to return on error or type mismatch
     */
    public function evaluateNumber(string $key, array $context = [], float $fallback = 0.0): float
    {
        try {
            return $this->evaluateResult($key, $context)->numberValue($fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Evaluate a JSON flag.
     *
     * @param string $key Flag key
     * @param array<string, string> $context User/request context
     * @param mixed $fallback Value to return on error or type mismatch
     */
    public function evaluateJSON(string $key, array $context = [], mixed $fallback = null): mixed
    {
        try {
            return $this->evaluateResult($key, $context)->jsonValue($fallback);
        } catch (\Throwable) {
            return $fallback;
        }
    }

    /**
     * Clear all cached flag values.
     */
    public function invalidateCache(): void
    {
        $this->cache->flush();
    }

    /**
     * Clear a specific flag from cache.
     */
    public function invalidateFlag(string $key): void
    {
        $this->cache->invalidateFlag($key);
    }

    /**
     * Start SSE stream for real-time cache invalidation.
     */
    public function stream(): TogulStreamClient
    {
        if ($this->streamClient === null) {
            $this->streamClient = new TogulStreamClient($this->config, $this->cache);
        }
        return $this->streamClient;
    }

    /**
     * Register a listener for cache invalidation events.
     */
    public function onCacheInvalidated(callable $listener): void
    {
        if ($this->streamClient === null) {
            $this->streamClient = new TogulStreamClient($this->config, $this->cache);
        }
        $this->streamClient->onCacheInvalidated($listener);
    }

    /**
     * @throws GuzzleException
     * @throws TogulException
     */
    private function evaluate(string $key, array $context): EvaluateResult
    {
        if ($this->config->apiKey === '') {
            throw new TogulException('API key is required');
        }

        $lastException = null;

        for ($attempt = 0; $attempt < $this->config->retryCount; $attempt++) {
            if ($attempt > 0) {
                usleep($attempt * 100_000);
            }

            try {
                $response = $this->http->post('/api/v1/evaluate', [
                    'json' => [
                        'flag_key' => $key,
                        'environment_key' => $this->config->environment,
                        'context' => $context,
                    ],
                ]);

                $body = json_decode($response->getBody()->getContents(), true);

                return new EvaluateResult(
                    flagKey:   (string) ($body['flag_key'] ?? $key),
                    enabled:   (bool) ($body['enabled'] ?? false),
                    valueType: (string) ($body['value_type'] ?? ''),
                    rawValue:  $body['value'] ?? null,
                    reason:    (string) ($body['reason'] ?? ''),
                );
            } catch (RequestException $e) {
                $apiError = $this->toApiError($e->getResponse(), $e);
                $lastException = $apiError;

                if (!$this->shouldRetry($apiError->statusCode)) {
                    throw $apiError;
                }
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new TogulException(
            'All retries failed: ' . ($lastException?->getMessage() ?? 'unknown error'),
            previous: $lastException,
        );
    }

    private function buildCacheKey(string $key, array $context): string
    {
        ksort($context);

        $parts = [$key, $this->config->environment];
        foreach ($context as $contextKey => $contextValue) {
            $parts[] = $contextKey . '=' . $contextValue;
        }

        return implode(':', $parts);
    }

    private function shouldRetry(int $statusCode): bool
    {
        return $statusCode === 429 || $statusCode >= 500;
    }

    private function toApiError(?ResponseInterface $response, \Throwable $previous): TogulException
    {
        if ($response === null) {
            return new TogulException(
                'Request failed: ' . $previous->getMessage(),
                previous: $previous,
            );
        }

        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        $message = is_array($body) && isset($body['message'])
            ? (string) $body['message']
            : 'Unexpected status ' . $statusCode;
        $errorCode = is_array($body) && isset($body['code'])
            ? (string) $body['code']
            : null;

        return new TogulException(
            $message,
            statusCode: $statusCode,
            errorCode: $errorCode,
            previous: $previous,
        );
    }
}
