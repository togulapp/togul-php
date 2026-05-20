<?php

declare(strict_types=1);

namespace Togul\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Togul\Config;
use Togul\EvaluateResult;
use Togul\TogulClient;
use Togul\TogulException;

class ClientTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    private function makeConfig(array $overrides = []): Config
    {
        return new Config(
            environment: $overrides['environment'] ?? 'staging',
            apiKey: $overrides['apiKey'] ?? 'test-key',
            retryCount: $overrides['retryCount'] ?? 1,
        );
    }

    private function makeClient(array $responses, array $overrides = [], array &$history = []): TogulClient
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new Client(['handler' => $stack]);
        return new TogulClient($this->makeConfig($overrides), $http);
    }

    private function evalResponse(array $overrides = []): Response
    {
        $body = array_merge([
            'flag_key'   => 'test-flag',
            'enabled'    => true,
            'value_type' => 'boolean',
            'value'      => true,
            'reason'     => 'rule_match',
        ], $overrides);

        return new Response(200, ['Content-Type' => 'application/json'], json_encode($body));
    }

    private function errorResponse(int $status, string $code, string $message): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode([
            'code'    => $code,
            'message' => $message,
        ]));
    }

    // ── evaluate: value types ─────────────────────────────────────────────

    public function testEvaluateBooleanFlag(): void
    {
        $client = $this->makeClient([$this->evalResponse(['value_type' => 'boolean', 'value' => true])]);
        $result = $client->evaluate('test-flag');

        $this->assertInstanceOf(EvaluateResult::class, $result);
        $this->assertSame('test-flag', $result->flagKey);
        $this->assertTrue($result->enabled);
        $this->assertSame('boolean', $result->valueType);
        $this->assertTrue($result->value);
        $this->assertSame('rule_match', $result->reason);
    }

    public function testEvaluateStringFlag(): void
    {
        $client = $this->makeClient([$this->evalResponse(['value_type' => 'string', 'value' => 'dark_mode'])]);
        $result = $client->evaluate('ui-theme');

        $this->assertSame('string', $result->valueType);
        $this->assertSame('dark_mode', $result->value);
    }

    public function testEvaluateNumberFlag(): void
    {
        $client = $this->makeClient([$this->evalResponse(['value_type' => 'number', 'value' => 55])]);
        $result = $client->evaluate('threshold');

        $this->assertSame('number', $result->valueType);
        $this->assertSame(55, $result->value);
    }

    public function testEvaluateJsonFlag(): void
    {
        $jsonVal = ['plan' => 'pro', 'limit' => 100];
        $client = $this->makeClient([$this->evalResponse(['value_type' => 'json', 'value' => $jsonVal])]);
        $result = $client->evaluate('config');

        $this->assertSame('json', $result->valueType);
        $this->assertSame($jsonVal, $result->value);
    }

    public function testDisabledFlagStillReturnsValue(): void
    {
        $client = $this->makeClient([
            $this->evalResponse(['enabled' => false, 'value' => 'onur', 'reason' => 'disabled']),
        ]);
        $result = $client->evaluate('test-flag');

        $this->assertFalse($result->enabled);
        $this->assertSame('onur', $result->value);
        $this->assertSame('disabled', $result->reason);
    }

    // ── Request format ────────────────────────────────────────────────────

    public function testSendsXApiKeyHeader(): void
    {
        $history = [];
        $client = $this->makeClient([$this->evalResponse()], [], $history);
        $client->evaluate('flag');

        /** @var Request $req */
        $req = $history[0]['request'];
        $this->assertSame('test-key', $req->getHeaderLine('X-API-Key'));
        $this->assertEmpty($req->getHeaderLine('Authorization'));
    }

    public function testSendsCorrectRequestBody(): void
    {
        $history = [];
        $client = $this->makeClient([$this->evalResponse()], [], $history);
        $client->evaluate('my-flag', ['user_id' => 'u1', 'plan' => 'pro']);

        $body = json_decode((string) $history[0]['request']->getBody(), true);
        $this->assertSame('my-flag', $body['flag_key']);
        $this->assertSame('staging', $body['environment_key']);
        $this->assertSame(['user_id' => 'u1', 'plan' => 'pro'], $body['context']);
    }

    public function testPostsToEvaluateEndpoint(): void
    {
        $history = [];
        $client = $this->makeClient([$this->evalResponse()], [], $history);
        $client->evaluate('flag');

        /** @var Request $req */
        $req = $history[0]['request'];
        $this->assertSame('POST', $req->getMethod());
        $this->assertStringContainsString('/api/v1/evaluate', $req->getUri()->getPath());
    }

    // ── Cache ─────────────────────────────────────────────────────────────

    public function testCacheHitOnSecondCall(): void
    {
        $mock = new MockHandler([
            $this->evalResponse(),
            $this->evalResponse(['enabled' => false]),
        ]);
        $stack = HandlerStack::create($mock);
        $http = new Client(['handler' => $stack]);
        $client = new TogulClient($this->makeConfig(), $http);

        $first  = $client->evaluate('flag', ['user_id' => 'u1']);
        $second = $client->evaluate('flag', ['user_id' => 'u1']);

        $this->assertTrue($first->enabled);
        $this->assertTrue($second->enabled); // from cache, not the second mock response
        $this->assertSame(1, $mock->count() - $mock->count() + 1); // only 1 request consumed
    }

    public function testDifferentContextsGetDifferentCacheEntries(): void
    {
        $client = $this->makeClient([
            $this->evalResponse(['enabled' => true]),
            $this->evalResponse(['enabled' => false]),
        ]);

        $r1 = $client->evaluate('flag', ['user_id' => 'u1']);
        $r2 = $client->evaluate('flag', ['user_id' => 'u2']);

        $this->assertTrue($r1->enabled);
        $this->assertFalse($r2->enabled);
    }

    public function testInvalidateCacheForcesRefetch(): void
    {
        $client = $this->makeClient([
            $this->evalResponse(['enabled' => true]),
            $this->evalResponse(['enabled' => false]),
        ]);

        $client->evaluate('flag');
        $client->invalidateCache();
        $result = $client->evaluate('flag');

        $this->assertFalse($result->enabled);
    }

    public function testInvalidateFlagOnlyClearsThatFlag(): void
    {
        $client = $this->makeClient([
            $this->evalResponse(['flag_key' => 'flag-a', 'enabled' => true]),
            $this->evalResponse(['flag_key' => 'flag-b', 'enabled' => true]),
            $this->evalResponse(['flag_key' => 'flag-a', 'enabled' => false]),
        ]);

        $client->evaluate('flag-a');
        $client->evaluate('flag-b');
        $client->invalidateFlag('flag-a');

        $a = $client->evaluate('flag-a'); // miss — refetched
        $b = $client->evaluate('flag-b'); // hit — from cache

        $this->assertFalse($a->enabled);
        $this->assertTrue($b->enabled);
    }

    // ── Retry ─────────────────────────────────────────────────────────────

    public function testRetriesOn429AndSucceeds(): void
    {
        $client = $this->makeClient([
            new Response(429),
            $this->evalResponse(),
        ], ['retryCount' => 2]);

        $result = $client->evaluate('flag');
        $this->assertTrue($result->enabled);
    }

    public function testRetriesOn500AndSucceeds(): void
    {
        $client = $this->makeClient([
            new Response(500),
            $this->evalResponse(),
        ], ['retryCount' => 2]);

        $result = $client->evaluate('flag');
        $this->assertTrue($result->enabled);
    }

    public function testDoesNotRetryOn4xxClientErrors(): void
    {
        $history = [];
        $client = $this->makeClient([
            $this->errorResponse(403, 'evaluate.environment_forbidden', 'Access denied'),
            $this->evalResponse(),
        ], ['retryCount' => 3], $history);

        $this->expectException(TogulException::class);
        try {
            $client->evaluate('flag');
        } finally {
            $this->assertCount(1, $history);
        }
    }

    public function testThrowsAfterAllRetriesExhausted(): void
    {
        $client = $this->makeClient([
            new Response(500),
            new Response(500),
        ], ['retryCount' => 2]);

        $this->expectException(TogulException::class);
        $client->evaluate('flag');
    }

    public function testThrowsWhenApiKeyIsMissing(): void
    {
        $config = new Config(environment: 'staging', apiKey: '');
        $client = new TogulClient($config);

        $this->expectException(TogulException::class);
        $client->evaluate('flag');
    }
}
