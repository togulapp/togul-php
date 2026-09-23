<?php

declare(strict_types=1);

namespace Togul\OpenFeature;

use DateTime;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Togul\EvaluateResult;
use Togul\TogulClient;
use Togul\TogulException;

/**
 * OpenFeature provider backed by TogulClient.
 *
 * Requires the optional "open-feature/sdk" package. Caching, retries and SSE
 * invalidation all stay in TogulClient; this class only adapts the single
 * evaluate() call to OpenFeature's typed resolvers and never throws.
 */
final class TogulProvider extends AbstractProvider
{
    protected static string $NAME = 'Togul';

    /**
     * @param string $targetingKeyAttribute Context attribute the OpenFeature
     *        targeting key is sent as. Matches the default rule "bucket_by".
     */
    public function __construct(
        private readonly TogulClient $client,
        private readonly string $targetingKeyAttribute = 'user_id',
    ) {}

    public function getClient(): TogulClient
    {
        return $this->client;
    }

    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'boolean',
            static fn (mixed $v): array => [is_bool($v), $v]);
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'string',
            static fn (mixed $v): array => [is_string($v), $v]);
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        // Togul has a single "number" type; json_decode yields float for "3.0".
        return $this->resolve($flagKey, $defaultValue, $context, 'integer',
            static function (mixed $v): array {
                if (is_int($v)) {
                    return [true, $v];
                }
                $whole = is_float($v) && is_finite($v) && floor($v) === $v
                    && $v >= PHP_INT_MIN && $v <= PHP_INT_MAX;
                return [$whole, $whole ? (int) $v : null];
            });
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'float',
            static fn (mixed $v): array => [is_int($v) || is_float($v), is_int($v) ? (float) $v : $v]);
    }

    /**
     * @param mixed[] $defaultValue
     */
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->resolve($flagKey, $defaultValue, $context, 'object',
            static fn (mixed $v): array => [is_array($v), $v]);
    }

    /**
     * @param callable(mixed): array{0: bool, 1: mixed} $coerce Returns [matches, coercedValue]
     */
    private function resolve(
        string $flagKey,
        bool|string|int|float|array $defaultValue,
        ?EvaluationContext $context,
        string $expectedType,
        callable $coerce,
    ): ResolutionDetails {
        try {
            $result = $this->client->evaluate($flagKey, $this->toTogulContext($context));
        } catch (TogulException $e) {
            $code = $e->statusCode === 404 && $e->errorCode === 'evaluate.flag_not_found'
                ? ErrorCode::FLAG_NOT_FOUND()
                : ErrorCode::GENERAL();
            return $this->error($defaultValue, $code, $e->getMessage());
        } catch (\Throwable $e) {
            return $this->error($defaultValue, ErrorCode::GENERAL(), $e->getMessage());
        }

        // OpenFeature spec: a disabled flag resolves to the caller's default.
        if (!$result->enabled) {
            return $this->details($defaultValue, Reason::DISABLED);
        }

        // A json flag created without a default stores null: nothing to serve.
        if ($result->value === null) {
            return $this->details($defaultValue, $this->mapReason($result));
        }

        [$matches, $value] = $coerce($result->value);
        if (!$matches) {
            return $this->error($defaultValue, ErrorCode::TYPE_MISMATCH(), sprintf(
                'Flag "%s" has value_type "%s", requested %s',
                $flagKey,
                $result->valueType,
                $expectedType,
            ));
        }

        return $this->details($value, $this->mapReason($result));
    }

    private function mapReason(EvaluateResult $result): string
    {
        return match ($result->reason) {
            'rule_match' => Reason::TARGETING_MATCH,
            'default'    => Reason::DEFAULT,
            'disabled'   => Reason::DISABLED,
            default      => Reason::UNKNOWN,
        };
    }

    /**
     * Togul evaluates against a flat map<string,string> (backend EvalContext),
     * and TogulClient builds its cache key by concatenating those values.
     *
     * @return array<string, string>
     */
    private function toTogulContext(?EvaluationContext $context): array
    {
        if ($context === null) {
            return [];
        }

        $out = [];
        foreach ($context->getAttributes()->toArray() as $key => $value) {
            $stringValue = $this->stringifyAttribute($value);
            if ($stringValue !== null) {
                $out[(string) $key] = $stringValue;
            }
        }

        // An explicitly set attribute wins over the targeting key.
        $targetingKey = $context->getTargetingKey();
        if ($targetingKey !== null && $targetingKey !== '' && !isset($out[$this->targetingKeyAttribute])) {
            $out[$this->targetingKeyAttribute] = $targetingKey;
        }

        return $out;
    }

    /**
     * Convert one OpenFeature attribute value to the string Togul rules compare against.
     * Return null to drop the attribute from the context.
     *
     * @param bool|string|int|float|DateTime|mixed[]|null $value
     */
    private function stringifyAttribute(bool|string|int|float|DateTime|array|null $value): ?string
    {
        // Rules compare with plain string equality ("eq", "in", ...), so every
        // format must match what users type in the dashboard.
        return match (true) {
            $value === null     => null,
            is_bool($value)     => $value ? 'true' : 'false',
            is_float($value)    => is_finite($value) ? (string) $value : null,
            $value instanceof DateTime => $value->format(DATE_ATOM),
            is_array($value)    => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null,
            default             => (string) $value,
        };
    }

    private function details(bool|string|int|float|array $value, string $reason): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder())
            ->withValue($value)
            ->withReason($reason)
            ->build();
    }

    private function error(bool|string|int|float|array $defaultValue, ErrorCode $code, string $message): ResolutionDetails
    {
        return (new ResolutionDetailsBuilder())
            ->withValue($defaultValue)
            ->withReason(Reason::ERROR)
            ->withError(new ResolutionError($code, $message))
            ->build();
    }
}
