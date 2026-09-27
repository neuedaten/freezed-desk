<?php

declare(strict_types=1);

namespace DeskOutbox;

/**
 * A successful publication: the platform's id of the post and its public
 * URL (a permalink; empty when the platform has none, e.g. mail).
 */
final class Result
{
    public function __construct(
        public readonly string $remoteId,
        public readonly string $url = '',
    ) {
    }
}
