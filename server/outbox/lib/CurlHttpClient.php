<?php

declare(strict_types=1);

namespace DeskOutbox;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private readonly string $userAgent = 'DeskOutbox/1.0')
    {
    }

    public function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 60): HttpResponse
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new HttpException('Could not initialise cURL.');
        }

        $lines = ['User-Agent: ' . $this->userAgent];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $responseHeaders = [];
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            if (is_array($body)) {
                $hasFile = false;
                foreach ($body as $value) {
                    if ($value instanceof \CURLFile) {
                        $hasFile = true;
                    }
                }
                curl_setopt($curl, CURLOPT_POSTFIELDS, $hasFile ? $body : http_build_query($body));
            } else {
                curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
            }
        }

        $content = curl_exec($curl);
        if ($content === false) {
            $errno = curl_errno($curl);
            $error = curl_error($curl);
            // A timeout after the upload finished may mean the platform got it.
            $sent = $errno === CURLE_OPERATION_TIMEDOUT && (int) curl_getinfo($curl, CURLINFO_SIZE_UPLOAD) > 0;
            throw new HttpException('HTTP ' . strtoupper($method) . ' ' . (string) parse_url($url, PHP_URL_HOST) . ' failed: ' . $error, $sent);
        }
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        return new HttpResponse($status, (string) $content, $responseHeaders);
    }
}
