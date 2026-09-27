<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * The one way adapters talk to platforms. CurlHttpClient in production, a
 * replaying fake in tests.
 */
interface HttpClient
{
    /**
     * @param array<string, string> $headers
     * @param string|array<string, mixed>|null $body A string is sent as is; an array
     *        is sent as multipart/form-data when it holds a CURLFile, as
     *        application/x-www-form-urlencoded otherwise. JSON: encode it and set
     *        the Content-Type header yourself.
     * @throws HttpException On a transport error (DNS, connect, timeout); never for an HTTP status.
     */
    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 60): HttpResponse;
}
