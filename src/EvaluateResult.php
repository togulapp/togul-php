<?php

declare(strict_types=1);

namespace Togul;

class EvaluateResult
{
    public function __construct(
        public readonly string $flagKey,
        public readonly bool $enabled,
        public readonly string $valueType,
        public readonly mixed $value,
        public readonly string $reason,
    ) {}
}
