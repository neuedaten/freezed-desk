<?php

namespace Neuedaten\FreezedDesk\Export;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Storage\Item;

/**
 * Where the built page of a record is served (the "Preview" button,
 * `preview-url`, A1.10): targetDirectory and targetFileExtension of the
 * content type, or the targetFileName a "variables" callback sets (for
 * pages below /km-70/<slug>/ and the like). Null for desk-only types.
 */
final class PreviewUrl
{
    public function __construct(private readonly DeskContext $context)
    {
    }

    public function of(Item $item): ?string
    {
        $path = $this->path($item);

        return $path === null ? null : $this->context->config->previewUrl() . $path;
    }

    /**
     * The absolute URL on the live site (siteUrl of freezed.config.php), for
     * links that leave the desk -- social posts, mails.
     */
    public function live(Item $item): ?string
    {
        $path = $this->path($item);
        $siteUrl = rtrim((string) \Neuedaten\Freezed\Services\ConfigService::getInstance()->getValue('[siteUrl]'), '/');

        return $path === null || $siteUrl === '' ? null : $siteUrl . $path;
    }

    /** The path of the built page below the site root ("/km-70/seeblick/"), or null. */
    public function path(Item $item): ?string
    {
        $schema = $this->context->schemas()->get($item->type);
        if (!$schema->built) {
            return null;
        }
        $config = $this->context->contentTypeConfig($schema->slug) ?? [];
        $directory = trim(str_replace('\\', '/', (string) ($config['targetDirectory'] ?? '')), '/');
        $extension = (string) ($config['targetFileExtension'] ?? 'html');

        $file = $item->slug . '.' . $extension;
        if ($schema->variablesCallback !== null) {
            try {
                $variables = $this->context->exporter()->export($item, $schema);
                if (isset($variables['targetFileName']) && is_string($variables['targetFileName']) && $variables['targetFileName'] !== '') {
                    $file = ltrim($variables['targetFileName'], '/');
                    if (pathinfo($file, PATHINFO_EXTENSION) === '') {
                        $file .= '.' . $extension;
                    }
                }
            } catch (\Throwable) {
                // A broken callback must not break the preview link.
            }
        }

        return '/' . ($directory !== '' ? $directory . '/' : '') . preg_replace('/(^|\/)index\.[a-z0-9]+$/i', '$1', $file);
    }
}
