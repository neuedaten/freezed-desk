<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Server;

use DeskOutbox\AdapterContext;
use DeskOutbox\AdapterException;
use DeskOutbox\Adapters\LogAdapter;
use DeskOutbox\Adapters\MailAdapter;
use DeskOutbox\Adapters\WebhookAdapter;
use DeskOutbox\Asset;
use DeskOutbox\AssetUrls;
use DeskOutbox\HttpException;
use DeskOutbox\HttpResponse;
use DeskOutbox\Message;
use Neuedaten\FreezedDesk\Tests\Server\Support\CollectingMailer;
use Neuedaten\FreezedDesk\Tests\Server\Support\FakeHttpClient;
use Neuedaten\FreezedDesk\Tests\Server\Support\MemoryState;
use PHPUnit\Framework\TestCase;

final class AdaptersTest extends TestCase
{
    private string $directory;

    private FakeHttpClient $http;

    private CollectingMailer $mailer;

    private MemoryState $state;

    /** @var string[] */
    private array $logged = [];

    private string $sha;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/desk-outbox-adapters-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $bytes = "\xFF\xD8\xFF\xE0test";
        $this->sha = hash('sha256', $bytes);
        file_put_contents($this->directory . '/' . $this->sha . '.jpg', $bytes);
        $this->http = new FakeHttpClient();
        $this->mailer = new CollectingMailer();
        $this->state = new MemoryState();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    private function context(): AdapterContext
    {
        return new AdapterContext(
            http: $this->http,
            state: $this->state,
            log: function (string $line): void {
                $this->logged[] = $line;
            },
            clock: static fn (): \DateTimeImmutable => new \DateTimeImmutable('2027-05-06T10:00:00Z'),
            mailer: $this->mailer,
        );
    }

    private function message(array $payload = []): Message
    {
        return new Message(
            'posts/2027-05-06-km-70#whatsapp',
            'whatsapp',
            new \DateTimeImmutable('2027-05-06T10:00:00Z'),
            $payload + ['kind' => 'image', 'text' => "Kilometer 70: der Baldeneysee 🌊\nMehr im Link.", 'media' => [['sha256' => $this->sha, 'mime' => 'image/jpeg']]],
            [new Asset($this->sha, 'image/jpeg')],
        );
    }

    private function urls(Message $message): AssetUrls
    {
        $map = [];
        foreach ($message->assets as $asset) {
            $map[$asset->sha256] = $asset;
        }

        return new AssetUrls('https://example.org/api/v1/outbox/media', $this->directory, $map);
    }

    // ------------------------------------------------------------------ log ---

    public function testLogAdapterWritesALine(): void
    {
        $file = $this->directory . '/sent.log';
        $adapter = new LogAdapter(['file' => $file], $this->context());
        $message = $this->message();
        self::assertSame([], $adapter->validate($message));
        $result = $adapter->publish($message, $this->urls($message));

        self::assertMatchesRegularExpression('/^log-[a-f0-9]{12}$/', $result->remoteId);
        self::assertSame('', $result->url);
        $line = (string) file_get_contents($file);
        self::assertStringContainsString('posts/2027-05-06-km-70#whatsapp', $line);
        self::assertStringContainsString(substr($this->sha, 0, 12), $line);
        self::assertStringNotContainsString('Baldeneysee', $line, 'no payload text in logs');
        self::assertTrue($adapter->health()['ok']);
    }

    public function testLogAdapterUsesContextLogWithoutFile(): void
    {
        $adapter = new LogAdapter([], $this->context());
        $adapter->publish($this->message(), $this->urls($this->message()));
        self::assertCount(1, $this->logged);
    }

    public function testLogAdapterSimulatesFailures(): void
    {
        foreach (['temporary' => [true, false], 'permanent' => [false, false], 'accepted' => [false, true]] as $fail => [$temporary, $accepted]) {
            try {
                (new LogAdapter(['fail' => $fail], $this->context()))->publish($this->message(), $this->urls($this->message()));
                self::fail('Expected an exception for ' . $fail);
            } catch (AdapterException $exception) {
                self::assertSame([$temporary, $accepted], [$exception->temporary, $exception->accepted], $fail);
            }
        }
        self::assertNotSame([], (new LogAdapter(['fail' => 'sometimes'], $this->context()))->validate($this->message()));
    }

    public function testLogAdapterFailTimes(): void
    {
        $adapter = new LogAdapter(['fail' => 'temporary', 'failTimes' => 1], $this->context());
        try {
            $adapter->publish($this->message(), $this->urls($this->message()));
            self::fail('first attempt should fail');
        } catch (AdapterException) {
        }
        self::assertStringStartsWith('log-', $adapter->publish($this->message(), $this->urls($this->message()))->remoteId);
    }

    // ----------------------------------------------------------------- mail ---

    public function testMailAdapterSendsTextAndFiles(): void
    {
        $adapter = new MailAdapter(['to' => 'kanal@example.org'], $this->context());
        $message = $this->message(['link' => ['url' => 'https://perlen.example/km-70']]);
        self::assertSame([], $adapter->validate($message));
        $result = $adapter->publish($message, $this->urls($message));

        self::assertMatchesRegularExpression('/^mail-[a-f0-9]{12}$/', $result->remoteId);
        self::assertCount(1, $this->mailer->mails);
        $mail = $this->mailer->mails[0];
        self::assertSame('kanal@example.org', $mail['to']);
        self::assertSame('whatsapp: Kilometer 70: der Baldeneysee 🌊', $mail['subject']);
        self::assertStringEndsWith("\n\nhttps://perlen.example/km-70", $mail['text']);
        self::assertSame($this->directory . '/' . $this->sha . '.jpg', $mail['attachments'][0]['path']);
        self::assertSame('image/jpeg', $mail['attachments'][0]['mime']);
        self::assertStringEndsWith('.jpg', $mail['attachments'][0]['name']);
    }

    public function testMailAdapterSubjectOptionAndValidation(): void
    {
        $adapter = new MailAdapter(['to' => 'kanal@example.org', 'from' => 'outbox@example.org'], $this->context());
        $message = $this->message(['options' => ['subject' => 'Für den Kanal']]);
        $adapter->publish($message, $this->urls($message));
        self::assertSame('Für den Kanal', $this->mailer->mails[0]['subject']);
        self::assertSame('outbox@example.org', $this->mailer->mails[0]['from']);

        self::assertNotSame([], (new MailAdapter([], $this->context()))->validate($message));
    }

    public function testMailAdapterRefusedMailIsTemporary(): void
    {
        $this->mailer->accept = false;
        $this->expectExceptionObject(new AdapterException('Mail adapter: the mail could not be handed to the mail system.', temporary: true));
        try {
            (new MailAdapter(['to' => 'kanal@example.org'], $this->context()))->publish($this->message(), $this->urls($this->message()));
        } catch (AdapterException $exception) {
            self::assertTrue($exception->temporary);
            self::assertFalse($exception->accepted);
            throw $exception;
        }
    }

    // -------------------------------------------------------------- webhook ---

    public function testWebhookPostsSignedJson(): void
    {
        $this->http->push(new HttpResponse(201, '{"id":"hook-42","url":"https://hooks.example.org/p/42"}'));
        $adapter = new WebhookAdapter(['url' => 'https://hooks.example.org/outbox', 'secret' => 's3cret'], $this->context());
        $message = $this->message();
        self::assertSame([], $adapter->validate($message));
        $result = $adapter->publish($message, $this->urls($message));

        self::assertSame('hook-42', $result->remoteId);
        self::assertSame('https://hooks.example.org/p/42', $result->url);
        $request = $this->http->requests[0];
        self::assertSame('POST', $request['method']);
        self::assertSame('https://hooks.example.org/outbox', $request['url']);
        self::assertSame('sha256=' . hash_hmac('sha256', (string) $request['body'], 's3cret'), $request['headers']['X-Outbox-Signature']);
        $body = json_decode((string) $request['body'], true);
        self::assertSame('posts/2027-05-06-km-70#whatsapp', $body['key']);
        self::assertSame('https://example.org/api/v1/outbox/media/' . $this->sha . '.jpg', $body['assets'][0]['url']);
    }

    public function testWebhookWithoutIdGetsGeneratedOne(): void
    {
        $this->http->push(new HttpResponse(204, ''));
        $result = (new WebhookAdapter(['url' => 'https://hooks.example.org/outbox'], $this->context()))->publish($this->message(), $this->urls($this->message()));
        self::assertMatchesRegularExpression('/^webhook-[a-f0-9]{12}$/', $result->remoteId);
        self::assertArrayNotHasKey('X-Outbox-Signature', $this->http->requests[0]['headers']);
    }

    /** @return iterable<string, array{HttpResponse|HttpException, bool, bool}> */
    public static function webhookFailures(): iterable
    {
        yield '429' => [new HttpResponse(429, 'slow down'), true, false];
        yield '503' => [new HttpResponse(503, 'maintenance'), true, false];
        yield '400' => [new HttpResponse(400, '{"error":"bad"}'), false, false];
        yield 'connect failed' => [new HttpException('connect failed', false), true, false];
        yield 'timeout after sending' => [new HttpException('timeout', true), false, true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('webhookFailures')]
    public function testWebhookFailures(HttpResponse|HttpException $answer, bool $temporary, bool $accepted): void
    {
        $this->http->push($answer);
        try {
            (new WebhookAdapter(['url' => 'https://hooks.example.org/outbox'], $this->context()))->publish($this->message(), $this->urls($this->message()));
            self::fail('Expected an AdapterException');
        } catch (AdapterException $exception) {
            self::assertSame([$temporary, $accepted], [$exception->temporary, $exception->accepted]);
        }
    }

    public function testWebhookValidation(): void
    {
        self::assertNotSame([], (new WebhookAdapter(['url' => 'ftp://x'], $this->context()))->validate($this->message()));
        self::assertNotSame([], (new WebhookAdapter([], $this->context()))->validate($this->message()));
    }
}
