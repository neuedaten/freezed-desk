<?php

namespace Neuedaten\FreezedDesk\Outbox;

use Neuedaten\FreezedDesk\Exception\DeskException;

final class CurlTransport implements OutboxTransport
{
    public function request(string $method, string $url, array $headers, mixed $body = null): array
    {
        if (!function_exists('curl_init')) {
            throw new DeskException('The outbox needs the PHP extension curl.');
        }
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $curl = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 600,
        ];
        if (is_resource($body)) {
            $stat = fstat($body);
            $options[CURLOPT_UPLOAD] = true;
            $options[CURLOPT_INFILE] = $body;
            $options[CURLOPT_INFILESIZE] = (int) ($stat['size'] ?? 0);
        } elseif (is_string($body)) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);
        $response = curl_exec($curl);
        if ($response === false) {
            throw new DeskException('Outbox request failed: ' . curl_error($curl));
        }

        return ['status' => (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'body' => (string) $response];
    }
}
