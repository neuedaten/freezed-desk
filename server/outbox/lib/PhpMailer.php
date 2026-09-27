<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * Mailer over PHP's mail(): the host's MTA relays it, no SMTP credentials
 * on the server. Plain text in UTF-8, files as base64 attachments in a
 * multipart/mixed message.
 */
final class PhpMailer implements Mailer
{
    /** @var \Closure(string, string, string, string): bool */
    private readonly \Closure $transport;

    /**
     * @param \Closure(string $to, string $subject, string $body, string $headers): bool|null $transport
     *        Replaces mail() in tests.
     */
    public function __construct(private readonly ?string $defaultFrom = null, ?\Closure $transport = null)
    {
        $this->transport = $transport ?? static fn (string $to, string $subject, string $body, string $headers): bool => mail($to, $subject, $body, $headers);
    }

    public function send(string $to, string $subject, string $text, array $attachments = [], ?string $from = null): bool
    {
        $to = self::headerValue($to);
        if ($to === '') {
            return false;
        }
        $from = self::headerValue((string) ($from ?? $this->defaultFrom ?? ''));
        $headers = [];
        if ($from !== '') {
            $headers[] = 'From: ' . $from;
        }
        $headers[] = 'MIME-Version: 1.0';

        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $textPart = chunk_split(base64_encode($text), 76, "\r\n");

        $files = array_values(array_filter($attachments, static fn (array $a): bool => is_file((string) ($a['path'] ?? ''))));
        if ($files === []) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';
            $body = $textPart;
        } else {
            $boundary = 'outbox-' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = "This is a MIME message.\r\n\r\n"
                . '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . $textPart;
            foreach ($files as $file) {
                $name = self::fileName((string) ($file['name'] ?? basename((string) $file['path'])));
                $mime = preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string) ($file['mime'] ?? '')) ? (string) $file['mime'] : 'application/octet-stream';
                $body .= '--' . $boundary . "\r\n"
                    . 'Content-Type: ' . $mime . '; name="' . $name . "\"\r\n"
                    . "Content-Transfer-Encoding: base64\r\n"
                    . 'Content-Disposition: attachment; filename="' . $name . "\"\r\n\r\n"
                    . chunk_split(base64_encode((string) file_get_contents((string) $file['path'])), 76, "\r\n");
            }
            $body .= '--' . $boundary . "--\r\n";
        }

        return ($this->transport)($to, self::encodeSubject($subject), $body, implode("\r\n", $headers));
    }

    /** RFC 2047 encoded-word, so umlauts survive every mail client. */
    public static function encodeSubject(string $subject): string
    {
        $subject = trim((string) preg_replace('/[\r\n\t]+/', ' ', $subject));
        if (!preg_match('/[^\x20-\x7e]/', $subject)) {
            return $subject;
        }

        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    /** No line breaks in header values: they would let a value add headers. */
    private static function headerValue(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
    }

    /** An ASCII file name; the files are named by sha256 anyway. */
    private static function fileName(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return trim($name, '-') !== '' ? $name : 'attachment';
    }
}
