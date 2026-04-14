<?php

declare(strict_types=1);

namespace Togul;

class Cache
{
    /** @var array<string, array{value: bool, expires_at: float}> */
    private array $store = [];

    public function __construct(
        private readonly int $ttl,
    ) {}

    public function get(string $key): ?bool
    {
        if (!isset($this->store[$key])) {
            return null;
        }

        $entry = $this->store[$key];
        if (microtime(true) > $entry['expires_at']) {
            unset($this->store[$key]);
            return null;
        }

        return $entry['value'];
    }

    public function set(string $key, bool $value): void
    {
        $this->store[$key] = [
            'value' => $value,
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
