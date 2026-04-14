<?php

declare(strict_types=1);

namespace Togul;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TogulStreamClient
{
    private Client $http;
    /** @var callable[] */
    private array $listeners = [];

    public function __construct(
        private readonly Config $config,
        private readonly Cache $cache,
    ) {
        $headers = ['Accept' => 'text/event-stream'];
        if ($this->config->apiKey !== '') {
            $headers['X-API-Key'] = $this->config->apiKey;
        }

        $this->http = new Client([
            'base_uri' => rtrim($this->config->baseUrl, '/'),
            'timeout' => 0,
            'headers' => $headers,
        ]);
    }

    public function connect(): void
    {
        $backoff = 1;

        while (true) {
            try {
                $this->streamOnce();
            } catch (\Throwable $e) {
                if ($this->isAuthError($e)) {
                    throw new TogulException(
                        'Stream authentication failed: ' . $e->getMessage(),
                        previous: $e,
                    );
                }

                usleep($backoff * 1_000_000);
                $backoff = min($backoff * 2, 30);
            }
        }
    }

    public function onCacheInvalidated(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    private function streamOnce(): void
    {
        if ($this->config->apiKey === '') {
            throw new TogulException('API key is required');
        }

        $response = $this->http->get('/api/v1/stream');

        if ($response->getStatusCode() !== 200) {
            throw new TogulException('Stream failed: ' . $response->getStatusCode());
        }

        $body = $response->getBody();
        $buffer = '';

        while (!$body->eof()) {
            $chunk = $body->read(8192);
            $buffer .= $chunk;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);

                if (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                    $this->handleEvent($data);
                }
            }
        }
    }

    private function handleEvent(string $data): void
    {
        $event = json_decode($data, true);
        if (!is_array($event)) {
            return;
        }

        $flagKey = $event['flag_key'] ?? '';

        if ($flagKey !== '') {
            $this->cache->invalidateFlag($flagKey);
            $this->notifyListeners($flagKey);
        } else {
            $this->cache->flush();
            $this->notifyListeners('');
        }
    }

    private function notifyListeners(string $flagKey): void
    {
        foreach ($this->listeners as $listener) {
            $listener($flagKey);
        }
    }

    private function isAuthError(\Throwable $e): bool
    {
        if ($e instanceof GuzzleException) {
            $response = $e->getResponse();
            if ($response !== null) {
                $status = $response->getStatusCode();
                return $status === 401 || $status === 403;
            }
        }
        return false;
    }
}