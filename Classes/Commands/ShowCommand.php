<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\Freezed\Services\ConfigService;
use Neuedaten\Freezed\Services\LogService;
use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;

/**
 * `freezed-desk show <type>/<slug>` — the variables a template of the
 * record sees, merged like the core merges them (site-wide, content type,
 * record). `show <type>` lists the records of a type; --raw prints the
 * stored data instead of the export.
 */
class ShowCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $target = $args[0] ?? '';

        if ($target === '') {
            $this->listTypes($context);
            return 0;
        }

        [$type, $slug] = array_pad(explode('/', $target, 2), 2, null);
        $schema = $context->schemas()->get($type);

        if ($slug === null || $slug === '') {
            if ($schema->single) {
                $slug = $context->repository()->findSingle($type)?->slug;
            }
            if ($slug === null) {
                $this->listRecords($context, $type);
                return 0;
            }
        }

        $item = $context->repository()->findBySlug($type, $slug)
            ?? throw new DeskException(sprintf('No record "%s" of type "%s".', $slug, $type));

        if (!empty($options['raw'])) {
            $this->print(['id' => $item->id] + $item->snapshot());
            return 0;
        }

        $variables = $context->exporter()->export($item, $schema);

        if ($schema->built) {
            $config = ConfigService::getInstance();
            $global = $config->getValue('[variables]') ?? [];
            $typeVariables = $context->contentTypeConfig($type)['variables'] ?? [];
            $variables = array_merge(is_array($global) ? $global : [], is_array($typeVariables) ? $typeVariables : [], $variables);
            $template = $schema->variant($item->variant)['template'] ?? 'index';
            LogService::getInstance()->notice(sprintf(
                '# %s/%s — status %s, template content/%s/%s.html',
                $type,
                $item->slug,
                $item->status->value,
                $type,
                $template
            ));
        } else {
            LogService::getInstance()->notice(sprintf('# %s/%s — status %s, desk-only type (not built)', $type, $item->slug, $item->status->value));
        }

        $this->print($variables);

        return 0;
    }

    private function listTypes(DeskContext $context): void
    {
        $repository = $context->repository();
        foreach ($context->schemas()->all() as $schema) {
            $counts = $repository->counts($schema->slug);
            fwrite(STDOUT, sprintf(
                "%-20s %-28s %s%s  draft %d, published %d, archived %d\n",
                $schema->slug,
                $schema->label,
                $schema->built ? 'built    ' : 'desk-only',
                $schema->single ? ', single' : '',
                $counts['draft'],
                $counts['published'],
                $counts['archived']
            ));
        }
    }

    private function listRecords(DeskContext $context, string $type): void
    {
        $items = $context->repository()->find($type)->anyStatus()->ordered()->all();
        if ($items === []) {
            fwrite(STDOUT, "(no records)\n");
            return;
        }
        foreach ($items as $item) {
            fwrite(STDOUT, sprintf("%-6d %-10s %-10s %-30s %s\n", $item->id, $item->status->value, $item->variant, $item->slug, $item->title));
        }
    }

    private function print(array $data): void
    {
        fwrite(STDOUT, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
