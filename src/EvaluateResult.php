<?php

declare(strict_types=1);

namespace Togul;

class EvaluateResult
{
    public function __construct(
        public readonly string $flagKey,
        public readonly bool $enabled,
        public readonly string $valueType,
        private readonly mixed $rawValue,
        public readonly string $reason,
    ) {}

    public function boolValue(bool $fallback = false): bool
    {
        if (!$this->enabled || $this->valueType !== 'boolean') {
            return $fallback;
        }
        return is_bool($this->rawValue) ? $this->rawValue : $fallback;
    }

    public function stringValue(string $fallback = ''): string
    {
        if (!$this->enabled || $this->valueType !== 'string') {
            return $fallback;
        }
        return is_string($this->rawValue) ? $this->rawValue : $fallback;
    }

    public function numberValue(float $fallback = 0.0): float
    {
        if (!$this->enabled || $this->valueType !== 'number') {
            return $fallback;
        }
        return is_numeric($this->rawValue) ? (float) $this->rawValue : $fallback;
    }

    public function jsonValue(mixed $fallback = null): mixed
    {
        if (!$this->enabled || $this->valueType !== 'json') {
            return $fallback;
        }
        return $this->rawValue ?? $fallback;
    }
}
