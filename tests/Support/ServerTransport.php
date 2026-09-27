<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Support;

use DeskOutbox\Api;
use DeskOutbox\Request;
use DeskOutbox\Services;
use Neuedaten\FreezedDesk\Outbox\OutboxTransport;

/**
 * Hands the outbox client's requests straight to the server module, so a
 * test runs Desk and server together without HTTP.
 */
final class ServerTransport implements OutboxTransport
{
    /** @var string[] */
    public array $calls = [];

    public function __construct(private readonly Services $services)
    {
    }

    public function request(string $method, string $url, array $headers, mixed $body = null): array
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = substr($path, (int) strpos($path, '/outbox') + strlen('/outbox')) ?: '/';
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->calls[] = $method . ' ' . $path;

        $response = (new Api($this->services))->handle(new Request($method, $path, $query, $headers, $body));
        $content = $response->body;
        if ($response->file !== null) {
            $content = (string) file_get_contents($response->file);
        }

        return ['status' => $response->status, 'body' => (string) $content];
    }
}
