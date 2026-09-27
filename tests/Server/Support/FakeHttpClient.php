<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server\Support;

use DeskOutbox\HttpClient;
use DeskOutbox\HttpException;
use DeskOutbox\HttpResponse;

/**
 * Replays queued responses (or exceptions) in order and records the requests.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<int, HttpResponse|HttpException> */
    private array $queue = [];

    /** @var array<int, array{method: string, url: string, headers: array<string, string>, body: string|array|null, timeout: int}> */
    public array $requests = [];

    public function push(HttpResponse|HttpException $answer): self
    {
        $this->queue[] = $answer;

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 60): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'timeout' => $timeout];
        $answer = array_shift($this->queue) ?? throw new \LogicException('No response queued for ' . $method . ' ' . $url);
        if ($answer instanceof HttpException) {
            throw $answer;
        }

        return $answer;
    }
}
