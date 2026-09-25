<?php

namespace Neuedaten\FreezedDesk\ViewHelpers;

use Neuedaten\FreezedDesk\DeskContext;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * A record as a small array (id, type, typeLabel, slug, title, status,
 * href, value) or null: {desk:record(id: pair.id)}.
 */
class RecordViewHelper extends AbstractViewHelper
{
    protected $escapeOutput = false;

    public function initializeArguments(): void
    {
        $this->registerArgument('id', 'mixed', 'Record id', false);
    }

    public function render(): ?array
    {
        return self::describe($this->arguments['id']);
    }

    /** @return array<string, mixed>|null */
    public static function describe(mixed $id): ?array
    {
        if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
            return null;
        }
        $context = DeskContext::get();
        $item = $context->repository()->get((int) $id);
        if ($item === null) {
            return null;
        }
        $schema = $context->schemas()->has($item->type) ? $context->schemas()->get($item->type) : null;

        return [
            'id' => $item->id,
            'type' => $item->type,
            'typeLabel' => $schema?->labelSingular ?? $item->type,
            'slug' => $item->slug,
            'title' => $item->title,
            'status' => $item->status->value,
            'href' => '/types/' . $item->type . '/' . $item->id,
            'value' => $item->type . ':' . $item->id,
        ];
    }
}
