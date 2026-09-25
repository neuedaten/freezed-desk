<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\FeaturesType;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * `freezed-desk agent` — a guide to this project's desk, written for an
 * agent (or a person in a hurry): the types and their fields, the commands
 * and the JSON they take, the rules. Everything in it is generated from the
 * schema, so it is never out of date.
 */
class AgentCommand extends AbstractCommand
{
    protected function run(DeskContext $context, array $args, array $options): int
    {
        $bin = self::binary($context);
        $lines = [];
        $lines[] = '# Desk of this project';
        $lines[] = '';
        $lines[] = 'Desk holds the structured content of this Freezed site in `' . $this->relative($context, $context->config->databasePath()) . '`. Work with it through the CLI below; every command prints JSON, errors come as `{"error": …, "errors": {field: message}}` with exit code 1. After changes, build the site with `vendor/bin/freezed build`.';
        $lines[] = '';
        $lines[] = '## Commands';
        $lines[] = '';
        $lines[] = '```bash';
        $lines[] = $bin . ' schema [<type>]                 # the schema as JSON (fields, options, variants, vocabularies)';
        $lines[] = $bin . ' list <type> [--status:all] [--q:text] [--limit:n]';
        $lines[] = $bin . ' get <type>/<slug>                # the record as JSON (editable form)';
        $lines[] = $bin . ' get <type>/<slug> --export       # what the template sees';
        $lines[] = $bin . ' put <type>[/<slug>] < record.json   # create or update; only given fields change';
        $lines[] = $bin . ' publish|unpublish|archive|delete <type>/<slug>';
        $lines[] = $bin . ' media:add <file> [--alt:"…"] [--credit:"…"]   # prints {"file": …} to reference';
        $lines[] = $bin . ' media:list [--q:text]';
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## Record JSON';
        $lines[] = '';
        $lines[] = 'A record has `slug`, `variant`, `status` (`draft`, `published`, `archived`), `sort` and `fields`. In `put`, everything but `fields` is optional and unknown field names are refused. Values by field type:';
        $lines[] = '';
        $lines[] = '- text, textarea, markdown: string. Markdown is converted to HTML at build time; raw HTML is stripped.';
        $lines[] = '- bool: true/false. number: number. date: `"YYYY-MM-DD"`. datetime: `"YYYY-MM-DDTHH:MM"`.';
        $lines[] = '- select: the option key (a list of keys when `multiple`).';
        $lines[] = '- link: `{"href": "https://… or CONTENT:pages/kontakt", "label": "…", "target": ""}`.';
        $lines[] = '- image: `{"file": "<file from media:add>"}`; images, files: a list of those.';
        $lines[] = '- relation: `{"type": "<type>", "slug": "<slug>"}`, a list of those when `multiple`. Targets must exist; only published targets appear on the site.';
        $lines[] = '- features: `{"<key>": value}` with keys from the vocabulary (true for bool features, the option for select, a number, a text).';
        $lines[] = '- hours: `{"days": {"mon": [{"from": "11:00", "to": "22:00"}], "sat": "10:00-14:00, 17:00-23:00"}, "note": "…"}` (days: mon … sun).';
        $lines[] = '- geo: `{"lat": 51.4, "lon": 7.02, "zoom": 14}`. list: a list of the element type. group: an object of its sub-fields. json: anything.';
        $lines[] = '';
        $lines[] = 'A required field must be filled before the record can be published; a draft may be incomplete. Publishing a record whose variant template is missing in `content/<type>/` is refused. `internal` fields are stored but never exported.';
        $lines[] = '';
        $lines[] = '## Types';
        $lines[] = '';

        foreach ($context->schemas()->all() as $schema) {
            $lines[] = '### `' . $schema->slug . '` — ' . $schema->label
                . ($schema->single ? ' (single: one record)' : '')
                . ($schema->built ? '' : ' (desk-only: not built, used as target or vocabulary)');
            $lines[] = '';
            if (count($schema->variants) > 1) {
                $lines[] = 'Variants: ' . implode(', ', array_map(static fn (string $k, array $v): string => '`' . $k . '` (' . $v['label'] . ', template `' . $v['template'] . '`)', array_keys($schema->variants), $schema->variants)) . '. Default: `' . $schema->defaultVariant() . '`.';
                $lines[] = '';
            }
            $lines[] = 'Title from `' . ($schema->titleField ?? '-') . '`' . ($schema->slugFrom ? ', slug from `' . $schema->slugFrom . '`' : '') . '. Fields:';
            $lines[] = '';
            foreach ($schema->fields as $name => $field) {
                $lines[] = '- ' . $this->describe($context, $name, $field);
            }
            $lines[] = '';
            $lines[] = 'Example: `' . $bin . ' list ' . $schema->slug . '`, `' . $bin . ' get ' . $schema->slug . '/<slug>`.';
            $lines[] = '';
        }

        $lines[] = '## Workflow';
        $lines[] = '';
        $lines[] = '1. `' . $bin . ' schema <type>` once, to see fields and options.';
        $lines[] = '2. `' . $bin . ' get <type>/<slug>` to read a record; edit the JSON; `' . $bin . ' put <type>/<slug> < file.json` to write it back. Unchanged fields may be left out.';
        $lines[] = '3. For a new record: `' . $bin . ' put <type> < file.json` with at least the title field; the slug is derived from it. Add `"status": "published"` when it is complete.';
        $lines[] = '4. Images: `' . $bin . ' media:add photo.jpg --alt:"…"` first, then reference `{"file": …}` from the answer.';
        $lines[] = '5. `vendor/bin/freezed build` renders the site; `' . $bin . ' get <type>/<slug> --export` shows the variables a template receives.';
        $lines[] = '';

        fwrite(STDOUT, implode("\n", $lines) . "\n");

        return 0;
    }

    private function describe(DeskContext $context, string $name, FieldDefinition $field, int $depth = 0): string
    {
        $parts = ['`' . $name . '` ' . $field->type];
        if ($field->required) {
            $parts[] = 'required';
        }
        if ($field->internal) {
            $parts[] = 'internal';
        }
        if ($name !== 'item') {
            $parts[] = '"' . $field->label . '"';
        }

        switch ($field->type) {
            case 'select':
                $type = $context->fieldTypes()->get('select');
                /** @var \Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType $type */
                $options = $type->options($field);
                $parts[] = 'options: ' . implode(', ', array_map(static fn (string $k, string $l): string => '`' . $k . '` (' . $l . ')', array_keys($options), $options));
                if ($field->get('multiple')) {
                    $parts[] = 'multiple';
                }
                break;
            case 'relation':
                $to = $field->get('to');
                $parts[] = 'to: ' . implode(', ', array_map(static fn ($t): string => '`' . $t . '`', (array) $to));
                if ($field->get('multiple')) {
                    $parts[] = 'multiple';
                }
                break;
            case 'features':
                $type = $context->fieldTypes()->get('features');
                if ($type instanceof FeaturesType) {
                    $keys = [];
                    foreach ($type->vocabulary($field, $context) as $key => $entry) {
                        $keys[] = '`' . $key . '` (' . $entry['kind'] . ($entry['options'] !== [] ? ': ' . implode('|', $entry['options']) : '') . ')';
                    }
                    $parts[] = 'keys: ' . ($keys === [] ? 'none yet, add records to `' . $field->get('from') . '`' : implode(', ', $keys));
                }
                break;
            case 'text':
            case 'textarea':
                if ($field->get('maxLength')) {
                    $parts[] = 'max ' . $field->get('maxLength') . ' chars';
                }
                break;
            case 'number':
                foreach (['min', 'max', 'step', 'unit'] as $option) {
                    if ($field->get($option) !== null) {
                        $parts[] = $option . ' ' . $field->get($option);
                    }
                }
                break;
            case 'images':
            case 'files':
            case 'list':
                if ($field->get('max')) {
                    $parts[] = 'max ' . $field->get('max');
                }
                break;
        }

        $line = implode(', ', $parts);

        if ($field->fields !== null) {
            $subs = [];
            foreach ($field->fields as $subName => $sub) {
                $subs[] = $this->describe($context, $subName, $sub, $depth + 1);
            }
            $line .= ' — fields: ' . implode('; ', $subs);
        }
        if ($field->of !== null) {
            $line .= ' — of: ' . $this->describe($context, 'item', $field->of, $depth + 1);
        }

        return $line;
    }

    private static function binary(DeskContext $context): string
    {
        return is_file($context->config->projectRoot . '/vendor/bin/freezed-desk') ? 'vendor/bin/freezed-desk' : 'freezed-desk';
    }

    private function relative(DeskContext $context, string $path): string
    {
        $root = $context->config->projectRoot;

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/') : $path;
    }
}
