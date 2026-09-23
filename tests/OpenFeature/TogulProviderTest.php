<?php

declare(strict_types=1);

namespace Togul\Tests\OpenFeature;

use DateTime;
use DateTimeZone;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\interfaces\flags\Client as OpenFeatureClient;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\Reason;
use OpenFeature\isolated\OpenFeatureAPIFactory;
use PHPUnit\Framework\TestCase;
use Togul\Config;
use Togul\OpenFeature\TogulProvider;
use Togul\TogulClient;

class TogulProviderTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Builds a real OpenFeature client on an isolated API instance, so the
     * SDK's own value-type validation runs against the provider's output.
     */
    private function makeOpenFeatureClient(array $responses, array &$history = []): OpenFeatureClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));

        $togul = new TogulClient(
            new Config(environment: 'staging', apiKey: 'test-key', retryCount: 1),
            new Client(['handler' => $stack]),
        );

        $api = OpenFeatureAPIFactory::createAPI();
        $api->setProvider(new TogulProvider($togul));
        return $api->getClient();
    }

    private function evalResponse(string $valueType, mixed $value, string $reason = 'rule_match', bool $enabled = true): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'flag_key'   => 'test-flag',
            'enabled'    => $enabled,
            'value_type' => $valueType,
            'value'      => $value,
            'reason'     => $reason,
        ]));
    }

    private function rawEvalResponse(string $json): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], $json);
    }

    private function errorResponse(int $status, string $code): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'code'    => $code,
            'message' => 'boom',
        ]));
    }

    private function sentContext(array $history): array
    {
        $this->assertCount(1, $history, 'provider failed before sending the evaluate request');
        $body = json_decode((string) $history[0]['request']->getBody(), true);
        return $body['context'];
    }

    // ── Metadata ─────────────────────────────────────────────────────────────

    public function testMetadataName(): void
    {
        $provider = new TogulProvider(new TogulClient(new Config(environment: 'x', apiKey: 'k')));
        $this->assertSame('Togul', $provider->getMetadata()->getName());
    }

    // ── Typed resolution ─────────────────────────────────────────────────────

    public function testBooleanRuleMatch(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('boolean', true)]);

        $details = $client->getBooleanDetails('test-flag', false);

        $this->assertTrue($details->getValue());
        $this->assertSame(Reason::TARGETING_MATCH, $details->getReason());
        $this->assertNull($details->getError());
    }

    public function testStringDefaultReason(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('string', 'dark', 'default')]);

        $details = $client->getStringDetails('test-flag', 'light');

        $this->assertSame('dark', $details->getValue());
        $this->assertSame(Reason::DEFAULT, $details->getReason());
    }

    public function testIntegerAcceptsWholeFloat(): void
    {
        $client = $this->makeOpenFeatureClient([$this->rawEvalResponse(
            '{"flag_key":"test-flag","enabled":true,"value_type":"number","value":3.0,"reason":"rule_match"}'
        )]);

        $this->assertSame(3, $client->getIntegerValue('test-flag', 0));
    }

    public function testIntegerRejectsFraction(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('number', 2.5)]);

        $details = $client->getIntegerDetails('test-flag', 7);

        $this->assertSame(7, $details->getValue());
        $this->assertEquals(ErrorCode::TYPE_MISMATCH(), $details->getError()->getResolutionErrorCode());
    }

    public function testFloatCastsInt(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('number', 4)]);

        $this->assertSame(4.0, $client->getFloatValue('test-flag', 0.0));
    }

    public function testObjectValue(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('json', ['limit' => 10])]);

        $this->assertSame(['limit' => 10], $client->getObjectValue('test-flag', []));
    }

    public function testObjectRejectsScalarJson(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('json', 'plain')]);

        $details = $client->getObjectDetails('test-flag', ['fallback' => true]);

        $this->assertSame(['fallback' => true], $details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
        $this->assertEquals(ErrorCode::TYPE_MISMATCH(), $details->getError()->getResolutionErrorCode());
    }

    public function testNullValueFallsBackWithoutError(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('json', null, 'default')]);

        $details = $client->getObjectDetails('test-flag', ['fallback' => true]);

        $this->assertSame(['fallback' => true], $details->getValue());
        $this->assertSame(Reason::DEFAULT, $details->getReason());
        $this->assertNull($details->getError());
    }

    public function testTypeMismatchBetweenFlagTypes(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('string', 'on')]);

        $details = $client->getBooleanDetails('test-flag', false);

        $this->assertFalse($details->getValue());
        $this->assertEquals(ErrorCode::TYPE_MISMATCH(), $details->getError()->getResolutionErrorCode());
    }

    // ── Disabled / errors ────────────────────────────────────────────────────

    public function testDisabledReturnsCallerDefault(): void
    {
        $client = $this->makeOpenFeatureClient([$this->evalResponse('string', 'server-default', 'disabled', enabled: false)]);

        $details = $client->getStringDetails('test-flag', 'caller-default');

        $this->assertSame('caller-default', $details->getValue());
        $this->assertSame(Reason::DISABLED, $details->getReason());
        $this->assertNull($details->getError());
    }

    public function testFlagNotFound(): void
    {
        $client = $this->makeOpenFeatureClient([$this->errorResponse(404, 'evaluate.flag_not_found')]);

        $details = $client->getBooleanDetails('missing', true);

        $this->assertTrue($details->getValue());
        $this->assertSame(Reason::ERROR, $details->getReason());
        $this->assertEquals(ErrorCode::FLAG_NOT_FOUND(), $details->getError()->getResolutionErrorCode());
    }

    public function testOtherApiErrorIsGeneral(): void
    {
        $client = $this->makeOpenFeatureClient([$this->errorResponse(401, 'auth.invalid_api_key')]);

        $details = $client->getBooleanDetails('test-flag', true);

        $this->assertTrue($details->getValue());
        $this->assertEquals(ErrorCode::GENERAL(), $details->getError()->getResolutionErrorCode());
    }

    // ── Context mapping ──────────────────────────────────────────────────────

    public function testTargetingKeyIsSentAsUserId(): void
    {
        $history = [];
        $client = $this->makeOpenFeatureClient([$this->evalResponse('boolean', true)], $history);

        $client->getBooleanValue('test-flag', false, new EvaluationContext('user-42'));

        $this->assertSame(['user_id' => 'user-42'], $this->sentContext($history));
    }

    public function testExplicitAttributeWinsOverTargetingKey(): void
    {
        $history = [];
        $client = $this->makeOpenFeatureClient([$this->evalResponse('boolean', true)], $history);

        $client->getBooleanValue('test-flag', false, new EvaluationContext(
            'from-targeting-key',
            new Attributes(['user_id' => 'explicit']),
        ));

        $this->assertSame(['user_id' => 'explicit'], $this->sentContext($history));
    }

    public function testAttributesAreStringified(): void
    {
        $history = [];
        $client = $this->makeOpenFeatureClient([$this->evalResponse('boolean', true)], $history);

        $client->getBooleanValue('test-flag', false, new EvaluationContext(null, new Attributes([
            'country'  => 'TR',
            'beta'     => true,
            'internal' => false,
            'age'      => 30,
            'score'    => 1.5,
            'whole'    => 2.0,
            'joined'   => new DateTime('2026-01-02T03:04:05', new DateTimeZone('UTC')),
            'tags'     => ['a/b', 'ş'],
            'nan'      => NAN,
            'missing'  => null,
        ])));

        $this->assertSame([
            'country'  => 'TR',
            'beta'     => 'true',
            'internal' => 'false',
            'age'      => '30',
            'score'    => '1.5',
            'whole'    => '2',
            'joined'   => '2026-01-02T03:04:05+00:00',
            'tags'     => '["a/b","ş"]',
        ], $this->sentContext($history));
    }

    public function testRepeatedEvaluationHitsClientCache(): void
    {
        // Same flag + same stringified context must hit TogulClient's cache.
        $history = [];
        $client = $this->makeOpenFeatureClient([$this->evalResponse('boolean', true)], $history);
        $context = new EvaluationContext('user-1', new Attributes(['beta' => true]));

        $client->getBooleanValue('test-flag', false, $context);
        $second = $client->getBooleanDetails('test-flag', false, $context);

        $this->assertCount(1, $history);
        $this->assertTrue($second->getValue());
    }
}
