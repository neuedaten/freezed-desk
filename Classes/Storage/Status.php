<?php

namespace Neuedaten\FreezedDesk\Storage;

enum Status: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    public static function fromInput(mixed $input, Status $default = self::Draft): self
    {
        if ($input instanceof self) {
            return $input;
        }
        if (is_string($input)) {
            return self::tryFrom(strtolower(trim($input))) ?? $default;
        }

        return $default;
    }

    /** @return string[] */
    public static function values(): array
    {
        return array_map(static fn (self $s): string => $s->value, self::cases());
    }
}
