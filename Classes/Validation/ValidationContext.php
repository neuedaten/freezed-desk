<?php

namespace Neuedaten\FreezedDesk\Validation;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\TypeSchema;
use Neuedaten\FreezedDesk\Storage\Actor;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * What a schema's "validate", "warnings" and "guard" callbacks get besides
 * the fields:
 *
 *     'validate' => function (array $fields, ?Item $item, ValidationContext $ctx): array {
 *         return $ctx->publishing && $fields['caption'] === '' ? ['caption' => 'Text fehlt.'] : [];
 *     },
 *
 * publishing is true when the record is to be published or stays
 * published; messages then block like a missing required field. In a draft
 * they are hints. $item is the stored record before the change (null for a
 * new one), so a callback can compare old and new values.
 */
final readonly class ValidationContext
{
    public function __construct(
        public bool $publishing,
        public Actor $actor,
        public TypeSchema $schema,
        public DeskContext $desk,
        public string $slug = '',
    ) {
    }
}
