<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use DeskOutbox\PhpMailer;
use PHPUnit\Framework\TestCase;

final class PhpMailerTest extends TestCase
{
    /** @var array<int, array{string, string, string, string}> */
    private array $sent = [];

    private function mailer(): PhpMailer
    {
        return new PhpMailer('outbox@example.org', function (string $to, string $subject, string $body, string $headers): bool {
            $this->sent[] = [$to, $subject, $body, $headers];

            return true;
        });
    }

    public function testPlainTextWithEncodedSubject(): void
    {
        self::assertTrue($this->mailer()->send('redaktion@example.org', 'Grüße: fehlgeschlagen', "Zeile 1\nZeile 2"));
        [$to, $subject, $body, $headers] = $this->sent[0];
        self::assertSame('redaktion@example.org', $to);
        self::assertSame('=?UTF-8?B?' . base64_encode('Grüße: fehlgeschlagen') . '?=', $subject);
        self::assertStringContainsString('From: outbox@example.org', $headers);
        self::assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $headers);
        self::assertSame("Zeile 1\nZeile 2", base64_decode($body));
        self::assertSame('Plain subject', PhpMailer::encodeSubject("Plain\nsubject"));
    }

    public function testAttachmentsAsMultipart(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'outbox-mail');
        file_put_contents($file, 'BYTES');
        try {
            $this->mailer()->send("a@example.org\r\nBcc: evil@example.org", 'Bild', 'Text', [['path' => $file, 'name' => 'bild ä.jpg', 'mime' => 'image/jpeg']], 'other@example.org');
        } finally {
            unlink($file);
        }
        [$to, , $body, $headers] = $this->sent[0];
        self::assertStringNotContainsString("\r\nBcc", $to);
        self::assertStringContainsString('From: other@example.org', $headers);
        self::assertMatchesRegularExpression('/Content-Type: multipart\/mixed; boundary="(outbox-[a-f0-9]+)"/', $headers);
        self::assertStringContainsString('Content-Disposition: attachment; filename="bild-.jpg"', $body);
        self::assertStringContainsString(base64_encode('BYTES'), $body);
        self::assertStringContainsString(base64_encode('Text'), $body);
    }
}
