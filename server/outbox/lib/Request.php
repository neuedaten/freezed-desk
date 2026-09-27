<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * An HTTP request as the outbox module sees it: method, the path below
 * /outbox, query, headers and the body as a stream (uploads of 200 MB are
 * never read into memory). fromGlobals() builds it inside the web server,
 * tests build it directly.
 */
final class Request
{
    /** @var array<string, string> */
    private readonly array $headers;

    /** @var resource|null */
    private $stream = null;

    /**
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers Any case; stored lower-case.
     * @param string|resource|null  $body
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        array $headers = [],
        private readonly mixed $body = null,
    ) {
        $this->headers = array_change_key_case(array_map('strval', $headers), CASE_LOWER);
    }

    public static function fromGlobals(string $method, string $path): self
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (is_string($value) && str_starts_with((string) $name, 'HTTP_')) {
                $headers[str_replace('_', '-', substr((string) $name, 5))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $server => $header) {
            if (isset($_SERVER[$server]) && $_SERVER[$server] !== '') {
                $headers[$header] = (string) $_SERVER[$server];
            }
        }
        if (!isset($headers['AUTHORIZATION']) && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $headers['authorization'] = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        $input = fopen('php://input', 'rb');

        return new self(strtoupper($method), $path, $_GET, $headers, $input === false ? null : $input);
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearer(): string
    {
        return preg_match('/^Bearer\s+(.+)$/i', (string) $this->header('authorization'), $m) ? trim($m[1]) : '';
    }

    public function query(string $name): ?string
    {
        $value = $this->query[$name] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return resource */
    public function stream()
    {
        if ($this->stream !== null) {
            return $this->stream;
        }
        if (is_resource($this->body)) {
            return $this->stream = $this->body;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new \RuntimeException('Cannot open a temporary stream.');
        }
        fwrite($stream, is_string($this->body) ? $this->body : '');
        rewind($stream);

        return $this->stream = $stream;
    }

    /**
     * The body decoded as a JSON object; null when it is not one or longer
     * than $maxBytes.
     *
     * @return array<string, mixed>|null
     */
    public function json(int $maxBytes = 2 * 1024 * 1024): ?array
    {
        $raw = stream_get_contents($this->stream(), $maxBytes + 1);
        if ($raw === false || strlen($raw) > $maxBytes) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) && !array_is_list($data) || $data === [] ? $data : null;
    }
}
