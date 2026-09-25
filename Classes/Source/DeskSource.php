<?php

namespace Neuedaten\FreezedDesk\Source;

use Neuedaten\Freezed\Domain\Source\ContentSourceInterface;
use Neuedaten\Freezed\Exception\ContentSourceException;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * The content source that connects Desk to the build:
 *
 *     'entries' => [
 *         'targetDirectory' => 'entries',
 *         'targetFileExtension' => 'html',
 *         'source' => \Neuedaten\FreezedDesk\Source\DeskSource::class,
 *     ],
 *
 * Every published record of the type becomes an item: its slug, the template
 * of its variant (content/<type>/<template>.html) and its exported
 * variables. The database is opened read-only, so a build never writes.
 * getVersion() reports the newest change, which lets `freezed watch`
 * rebuild after every save in the desk UI.
 */
final class DeskSource implements ContentSourceInterface
{
    public function findAll(string $typeSlug, array $contentTypeConfig): iterable
    {
        $context = $this->context();

        try {
            if (!$context->schemas()->has($typeSlug)) {
                throw new ContentSourceException(sprintf(
                    'Content type "%s" uses DeskSource, but there is no schema for it. Add %s/%s.php.',
                    $typeSlug,
                    trim((string) $context->config->get('typesPath', 'desk/types'), '/'),
                    $typeSlug
                ));
            }

            $schema = $context->schemas()->get($typeSlug);
            $exporter = $context->exporter();
            $items = $context->repository()->find($typeSlug)->published()->ordered()->all();

            foreach ($items as $item) {
                $variant = $schema->variant($item->variant);
                if ($variant === null) {
                    throw new ContentSourceException(sprintf(
                        'Record %s/%s has the variant "%s", which %s does not declare.',
                        $typeSlug,
                        $item->slug,
                        $item->variant,
                        basename($schema->file)
                    ));
                }

                yield [
                    'slug' => $item->slug,
                    'template' => $variant['template'],
                    'variables' => $exporter->export($item, $schema),
                ];
            }
        } catch (DeskException $exception) {
            throw new ContentSourceException('Desk: ' . $exception->getMessage(), 0, $exception);
        }
    }

    public function getVersion(string $typeSlug, array $contentTypeConfig): ?string
    {
        $context = $this->context();

        if (!$context->database()->exists()) {
            return null;
        }

        try {
            return $context->repository()->version($typeSlug);
        } catch (DeskException) {
            return null;
        }
    }

    private function context(): DeskContext
    {
        return DeskContext::get(readOnly: true);
    }
}
