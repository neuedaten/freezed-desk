<?php

namespace Neuedaten\FreezedDesk\Web;

final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var \Closure|null A body producer for streamed responses. */
    private ?\Closure $stream = null;

    public function __construct(
        private string $body = '',
        private int $status = 200,
        array $headers = [],
    ) {
        $this->headers = $headers + ['Content-Type' => 'text/html; charset=UTF-8'];
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function redirect(string $location, int $status = 303): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function file(string $path, ?string $mime = null, bool $cache = true): self
    {
        $response = new self('', 200, [
            'Content-Type' => $mime ?? (mime_content_type($path) ?: 'application/octet-stream'),
            'Content-Length' => (string) filesize($path),
            'Cache-Control' => $cache ? 'private, max-age=86400' : 'no-store',
        ]);
        $response->stream = static function () use ($path): void {
            readfile($path);
        };

        return $response;
    }

    /**
     * A response whose body is produced while it is sent (action output).
     *
     * @param \Closure(callable(string): void): void $producer Receives a writer.
     */
    public static function streamed(\Closure $producer, string $mime = 'text/plain; charset=UTF-8'): self
    {
        $response = new self('', 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'no-store',
            'X-Accel-Buffering' => 'no',
        ]);
        $response->stream = static function () use ($producer): void {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            $producer(static function (string $chunk): void {
                echo $chunk;
                flush();
            });
        };

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->stream !== null) {
            ($this->stream)();
            return;
        }
        echo $this->body;
    }
}
