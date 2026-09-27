<?php

declare(strict_types=1);

namespace Neuedaten\FreezedDesk\Tests\Support;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Outbox\OutboxAsset;
use Neuedaten\FreezedDesk\Outbox\OutboxMapperInterface;
use Neuedaten\FreezedDesk\Outbox\OutboxMessage;
use Neuedaten\FreezedDesk\Outbox\OutboxResult;
use Neuedaten\FreezedDesk\Storage\Item;

/** One message per channel of a test post, with its image. */
final class TestPostsMapper implements OutboxMapperInterface
{
    public function messages(Item $item, DeskContext $context): array
    {
        $assets = [];
        $media = [];
        if (is_int($item->data['image'] ?? null)) {
            $file = $context->media()->require($item->data['image']);
            $asset = OutboxAsset::fromFile($context->media()->absolutePath($file));
            $assets[] = $asset;
            $media[] = ['sha256' => $asset->sha256, 'mime' => $asset->mime, 'role' => 'image', 'alt' => 'Bild'];
        }
        $messages = [];
        foreach ((array) ($item->data['channels'] ?? []) as $channel) {
            $messages[] = new OutboxMessage(
                'posts/' . $item->slug . '#' . $channel,
                (string) $channel,
                new \DateTimeImmutable((string) $item->data['at']),
                ['kind' => $media === [] ? 'text' : 'image', 'text' => (string) $item->data['text'], 'media' => $media, 'lang' => 'de'],
                $assets,
            );
        }

        return $messages;
    }

    public function applyResult(Item $item, OutboxResult $result): array
    {
        $results = is_array($item->data['results'] ?? null) ? $item->data['results'] : [];
        $results[$result->channel] = ['state' => $result->state, 'url' => $result->url, 'remoteId' => $result->remoteId];

        return ['results' => $results];
    }
}
