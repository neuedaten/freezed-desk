<?php

namespace Neuedaten\FreezedDesk\Outbox;

/**
 * HTTP for the outbox client. CurlTransport talks to the server; tests
 * hand requests straight to the server module.
 */
interface OutboxTransport
{
    /**
     * @param array<string, string> $headers
     * @param string|resource|null $body A string, or a stream for a file upload.
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $url, array $headers, mixed $body = null): array;
}
