<?php

declare(strict_types=1);

namespace Togul;

class Cache
{
    /** @var array<string, array{result: EvaluateResult, expires_at: float}> */
    private array $store = [];

    public function __construct(
        private readonly int $ttl,
    ) {}

    public function get(string $key): ?EvaluateResult
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];
        if (microtime(true) > $entry['expires_at']) {
            unset($this->store[$key]);
            return null;
        }

        // Treat entries with missing value_type as stale (legacy/invalid format).
        if ($entry['result']->valueType === '') {
            unset($this->store[$key]);
            return null;
        }

        return $entry['result'];
    }

    public function set(string $key, EvaluateResult $result): void
    {
        $this->store[$key] = [
            'result'     => $result,
            'expires_at' => microtime(true) + $this->ttl,
        ];
    }

    public function flush(): void
    {
        $this->store = [];
    }

    public function invalidateFlag(string $flagKey): void
    {
        $prefix = $flagKey . ':';
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->store[$key]);
            }
        }
    }
}
