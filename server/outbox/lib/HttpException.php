<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * A transport error: the request did not get an HTTP answer. "sent" says
 * whether the request body may have reached the server (a timeout while
 * waiting for the answer), which matters for publish calls.
 */
final class HttpException extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $sent = false)
    {
        parent::__construct($message);
    }
}
