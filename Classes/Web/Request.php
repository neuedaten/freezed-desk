<?php

namespace Neuedaten\FreezedDesk\Web;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly array $files,
        public readonly array $headers,
        public readonly string $body = '',
    ) {
    }

    public static function fromGlobals(): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = '/' . trim(rawurldecode($path), '/');

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = $method === 'POST' || $method === 'PUT' ? (string) file_get_contents('php://input') : '';

        $post = $_POST;
        if ($post === [] && $body !== '' && str_contains($headers['content-type'] ?? '', 'application/json')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $post = $decoded;
            }
        }

        return new self($method, $path, $_GET, $post, $_FILES, $headers, $body);
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function wantsJson(): bool
    {
        $accept = $this->header('accept') ?? '';

        return str_contains($accept, 'application/json') || $this->header('x-requested-with') === 'fetch';
    }

    /** The query string with some parameters replaced, for links that keep filters. */
    public function queryWith(array $changes): string
    {
        $query = array_merge($this->query, $changes);
        $query = array_filter($query, static fn (mixed $v): bool => $v !== null && $v !== '');

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
