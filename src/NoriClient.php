<?php

declare(strict_types=1);

namespace Nori;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class NoriClient
{
    private Client $http;
    private Cache $cache;

    public function __construct(
        private readonly Config $config,
    ) {
        $headers = ['Content-Type' => 'application/json'];
        if ($this->config->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->config->apiKey;
        }

        $this->http = new Client([
            'base_uri' => rtrim($this->config->baseUrl, '/'),
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
     *
     * @throws NoriException When the API is unreachable and fallback mode is FailClosed
     */
    public function isEnabled(string $key, array $context = []): bool
    {
        $cacheKey = $this->buildCacheKey($key, $context);

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $value = $this->evaluate($key, $context);
            $this->cache->set($cacheKey, $value);
            return $value;
        } catch (\Throwable $e) {
            return match ($this->config->fallbackMode) {
                FallbackMode::FailOpen => true,
                FallbackMode::FailClosed => false,
            };
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
     * @throws GuzzleException
     * @throws NoriException
     */
    private function evaluate(string $key, array $context): bool
    {
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
                return (bool) ($body['value'] ?? false);
            } catch (\Throwable $e) {
                $lastException = $e;
            }
        }

        throw new NoriException(
            'All retries failed: ' . ($lastException?->getMessage() ?? 'unknown error'),
            previous: $lastException,
        );
    }

    private function buildCacheKey(string $key, array $context): string
    {
        $cacheKey = $key . ':' . $this->config->environment;
        if (isset($context['user_id'])) {
            $cacheKey .= ':' . $context['user_id'];
        }
        return $cacheKey;
    }
}
