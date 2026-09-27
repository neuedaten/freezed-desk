<?php

namespace Neuedaten\FreezedDesk\Agent;

use Neuedaten\FreezedDesk\DeskContext;
use Neuedaten\FreezedDesk\Exception\DeskException;
use Neuedaten\FreezedDesk\Schema\FieldDefinition;
use Neuedaten\FreezedDesk\Schema\FieldTypes\FeaturesType;
use Neuedaten\FreezedDesk\Schema\TypeSchema;

/**
 * The guide `desk:agent` prints (A2): the part Desk generates from the
 * schema, the sections of extensions (outbox, social package) and the
 * project's own Markdown files in desk/agent/ (desk.agentPath), which carry
 * what only the project knows -- editorial rules, style, workflow.
 *
 * A project file may start with a front matter naming its types:
 *
 *     ---
 *     types: [posts, entries]
 *     ---
 *
 * `desk:agent <topic>` prints desk/agent/<topic>.md with the generated
 * description of those types, the extension sections for the topic and the
 * rules; `desk:agent` alone prints everything.
 */
final class AgentGuide
{
    /** The core commands, for the Markdown guide and the JSON form. */
    private const COMMANDS = [
        ['schema [<type>]', 'The schema as JSON: fields, options, variants, vocabularies.'],
        ['list <type> [--status:all] [--where:field=value] [--from:today --to:+14d] [--order:-updatedAt] [--fields:a,b] [--limit:n]', 'Records of a type; --where is repeatable (= != > >= < <= ~), --referencing:<type>/<slug>, --by:agent, --unseen.'],
        ['get <type>/<slug> [--export]', 'One record: fields, revision, updatedBy, validation. --export: what the template sees.'],
        ['put <type>[/<slug>] [--if-revision:n] [--dry-run] < record.json', 'Create or update; only the given fields change. With --if-revision the change is refused when someone saved in between.'],
        ['validate <type>/<slug> [--publishing]', 'Messages of the schema\'s checks, without saving.'],
        ['refs <type>/<slug>', 'Records that reference this one.'],
        ['revisions <type>/<slug>', 'The states of a record with revision number, actor and time.'],
        ['revision <type>/<slug> <n> [--diff]', 'An earlier state, --diff against the current one.'],
        ['restore <type>/<slug> <n> [--dry-run]', 'Bring an earlier state back (as a new change).'],
        ['publish|unpublish|archive <type>/<slug> … | <type> --where:… [--dry-run]', 'Change the status of one or several records.'],
        ['delete <type>/<slug> … [--dry-run]', 'Remove records for good. Only when told to.'],
        ['reorder <type> <slug> <slug> …', 'Order of a type sorted by "sort".'],
        ['media:add <file> [--alt:…] [--credit:…] [--extra:{…}]', 'Add a file to the library; prints {"file": …} to reference.'],
        ['media:list [--q:] [--kind:images|videos|files] [--where:socialOk=true] [--origin:generated]', 'The library.'],
        ['media:get|media:usage <id|file>', 'One file with metadata and usage.'],
        ['media:update <id|file> [--alt:] [--caption:] [--credit:] [--focal:x,y] [--extra:{…}] [--dry-run]', 'Change the metadata of a file.'],
        ['inbox:list [--status:new,open]', 'Submissions from the site\'s forms.'],
        ['inbox:show <id> | inbox:assign <id> <type>/<slug> | inbox:set <id> --status: --note:', 'Read and handle a submission.'],
        ['actions | action <name> | action <type>/<slug> <name>', 'List and run actions (build, record actions such as render).'],
        ['status', 'Overview: counts per type, drafts, recent changes, inbox, outbox, last build.'],
        ['preview-url <type>/<slug>', 'Address of the built page.'],
    ];

    public function __construct(private readonly DeskContext $context)
    {
    }

    /**
     * Every section of the full guide, in order.
     *
     * @return AgentSection[]
     */
    public function sections(): array
    {
        $sections = [$this->intro(), $this->commands(), $this->recordFormat()];
        foreach ($this->context->schemas()->all() as $schema) {
            $sections[] = $this->typeSection($schema);
        }
        $sections[] = $this->workflow();
        foreach ($this->context->extensions() as $extension) {
            foreach ($extension->agentSections($this->context) as $section) {
                $sections[] = $section;
            }
        }
        foreach ($this->projectFiles() as $section) {
            $sections[] = $section;
        }
        $sections[] = $this->rules();

        return $sections;
    }

    /**
     * The sections of one topic: the project file desk/agent/<topic>.md, the
     * generated description of the types it names, the extension sections
     * for the topic, and the intro and rules around them.
     *
     * @return AgentSection[]
     */
    public function topic(string $topic): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $topic)) {
            throw new DeskException(sprintf('"%s" is not a topic name.', $topic));
        }
        $file = null;
        foreach ($this->projectFiles() as $section) {
            if ($section->id === 'project:' . $topic) {
                $file = $section;
            }
        }
        $extensionSections = [];
        foreach ($this->context->extensions() as $extension) {
            foreach ($extension->agentSections($this->context) as $section) {
                if (in_array($topic, $section->topics, true)) {
                    $extensionSections[] = $section;
                }
            }
        }
        // The section named like the topic first, then the others that belong to it.
        usort($extensionSections, static fn (AgentSection $a, AgentSection $b): int => ($a->id === $topic ? 0 : 1) <=> ($b->id === $topic ? 0 : 1));
        if ($file === null && $extensionSections === []) {
            throw new DeskException(sprintf('No guide for "%s". Topics: %s.', $topic, implode(', ', $this->topics()) ?: 'none (add desk/agent/<topic>.md)'));
        }

        $types = $file?->types ?? [];
        foreach ($extensionSections as $section) {
            $types = array_merge($types, $section->types);
        }

        $sections = [$this->intro(), $this->commands()];
        if ($file !== null) {
            $sections[] = $file;
        }
        foreach ($extensionSections as $section) {
            $sections[] = $section;
        }
        if ($types !== []) {
            $sections[] = $this->recordFormat();
        }
        foreach (array_unique($types) as $type) {
            if ($this->context->schemas()->has($type)) {
                $sections[] = $this->typeSection($this->context->schemas()->get($type));
            }
        }
        $sections[] = $this->rules();

        return $sections;
    }

    /** @return string[] Topics a `desk:agent <topic>` accepts. */
    public function topics(): array
    {
        $topics = [];
        foreach ($this->projectFiles() as $section) {
            $topics[] = substr($section->id, strlen('project:'));
        }
        foreach ($this->context->extensions() as $extension) {
            foreach ($extension->agentSections($this->context) as $section) {
                foreach ($section->topics as $topic) {
                    $topics[] = $topic;
                }
            }
        }

        return array_values(array_unique($topics));
    }

    /** @param AgentSection[] $sections */
    public static function markdown(array $sections): string
    {
        $parts = [];
        foreach ($sections as $section) {
            $parts[] = rtrim($section->markdown);
        }

        return implode("\n\n", $parts) . "\n";
    }

    /**
     * The structured form (A2.5).
     *
     * @param AgentSection[] $sections
     * @return array<string, mixed>
     */
    public static function structured(array $sections): array
    {
        $commands = [];
        $rules = [];
        foreach ($sections as $section) {
            foreach ($section->commands as $command) {
                $commands[] = $command + ['section' => $section->id];
            }
            foreach ($section->rules as $rule) {
                $rules[] = ['rule' => $rule, 'section' => $section->id];
            }
        }

        return [
            'sections' => array_map(static fn (AgentSection $s): array => $s->toArray(), $sections),
            'commands' => $commands,
            'rules' => $rules,
        ];
    }

    /**
     * How commands are written in the guide, the prefix before a command
     * name: "vendor/bin/freezed desk:" in a project (the core's CLI, the way
     * the project's own docs call Desk), else "freezed-desk ".
     */
    public static function binary(DeskContext $context): string
    {
        if (is_file($context->config->projectRoot . '/vendor/bin/freezed')) {
            return 'vendor/bin/freezed desk:';
        }

        return is_file($context->config->projectRoot . '/vendor/bin/freezed-desk') ? 'vendor/bin/freezed-desk ' : 'freezed-desk ';
    }

    // ------------------------------------------------------ sections ---

    private function intro(): AgentSection
    {
        $bin = self::binary($this->context);

        return new AgentSection('intro', 'Desk of this project', implode("\n", [
            '# Desk of this project',
            '',
            'Desk holds the structured content of this Freezed site in `' . $this->relative($this->context->config->databasePath()) . '`. Work with it through the CLI: `' . $bin . '<command>` (the same commands also run as `vendor/bin/freezed-desk <command>`). Every command prints JSON; errors come as `{"error": …, "errors": {field: message}}` with exit code 1. Commands that change something take `--dry-run`.',
            '',
            '**Set `DESK_ACTOR=agent`** in the environment of every call (`export DESK_ACTOR=agent`), so your changes are recorded as the agent\'s and people can see what to review. The CLI refuses to act as "editor".',
        ]), topics: [], rules: ['Set DESK_ACTOR=agent for every call.']);
    }

    private function commands(): AgentSection
    {
        $bin = self::binary($this->context);
        $lines = ['## Commands', '', '```bash'];
        $commands = [];
        foreach (self::COMMANDS as [$usage, $description]) {
            $lines[] = $bin . '' . $usage;
            $lines[] = '    # ' . $description;
            $commands[] = ['command' => $bin . '' . $usage, 'description' => $description];
        }
        $lines[] = '```';

        return new AgentSection('commands', 'Commands', implode("\n", $lines), commands: $commands);
    }

    private function recordFormat(): AgentSection
    {
        return new AgentSection('records', 'Record JSON', implode("\n", [
            '## Record JSON',
            '',
            'A record has `slug`, `variant`, `status` (`draft`, `published`, `archived`), `sort` and `fields`; `get` adds `revision`, `updatedBy` and `validation`. In `put`, everything but `fields` is optional and unknown field names are refused. Values by field type:',
            '',
            '- text, textarea, markdown: string. Markdown is converted to HTML at build time; raw HTML is stripped.',
            '- bool: true/false. number: number. date: `"YYYY-MM-DD"`. datetime: `"YYYY-MM-DDTHH:MM"`.',
            '- select: the option key (a list of keys when `multiple`).',
            '- link: `{"href": "https://… or CONTENT:pages/kontakt", "label": "…", "target": ""}`.',
            '- image: `{"file": "<file from media:add>"}`; images, files: a list of those.',
            '- relation: `{"type": "<type>", "slug": "<slug>"}`, a list of those when `multiple`. Targets must exist; only published targets appear on the site.',
            '- features: `{"<key>": value}` with keys from the vocabulary (true for bool features, the option for select, a number, a text).',
            '- hours: `{"days": {"mon": [{"from": "11:00", "to": "22:00"}], "sat": "10:00-14:00, 17:00-23:00"}, "note": "…"}` (days: mon … sun).',
            '- geo: `{"lat": 51.4, "lon": 7.02, "zoom": 14}`. list: a list of the element type. group: an object of its sub-fields. json: anything.',
            '',
            'A required field must be filled before the record can be published. `internal` fields are stored but never exported -- and never belong in any text. `system` fields are written by Desk and its packages (rendered files, results); `put` ignores them.',
            '',
            '`validation.errors` block publishing; `validation.warnings` never do. Read them after every `put` and fix what they name.',
            '',
            'Work with `--if-revision:<revision from get>` on `put`: when a person saved in between, the answer is `{"error": "conflict: …", "current": …}` and nothing is written. Get the record again and re-apply your change.',
        ]));
    }

    private function typeSection(TypeSchema $schema): AgentSection
    {
        $bin = self::binary($this->context);
        $lines = [];
        $lines[] = '### `' . $schema->slug . '` — ' . $schema->label
            . ($schema->single ? ' (single: one record)' : '')
            . ($schema->built ? '' : ' (desk-only: not built)');
        $lines[] = '';
        if ($schema->needsUiApproval()) {
            $lines[] = '**Approval by a person:** records of this type are published ("approved") only in the desk UI. From the CLI, publishing is refused, and a change to a published record sends it back to draft.';
            $lines[] = '';
        }
        if (count($schema->variants) > 1) {
            $lines[] = 'Variants: ' . implode(', ', array_map(static fn (string $k, array $v): string => '`' . $k . '` (' . $v['label'] . ', template `' . $v['template'] . '`)', array_keys($schema->variants), $schema->variants)) . '. Default: `' . $schema->defaultVariant() . '`.';
            $lines[] = '';
        }
        $lines[] = 'Title from `' . ($schema->titleField ?? '-') . '`' . ($schema->slugFrom ? ', slug from `' . $schema->slugFrom . '`' : '') . '. Fields:';
        $lines[] = '';
        foreach ($schema->fields as $name => $field) {
            $lines[] = '- ' . $this->describe($name, $field);
        }
        if ($schema->actions !== []) {
            $lines[] = '';
            $lines[] = 'Record actions: ' . implode(', ', array_map(static fn (string $n, array $a): string => '`' . $n . '` (' . $a['label'] . ': `' . $a['command'] . '`)', array_keys($schema->actions), $schema->actions)) . '. Run with `' . $bin . 'action ' . $schema->slug . '/<slug> <name>`.';
        }
        $lines[] = '';
        $lines[] = 'Example: `' . $bin . 'list ' . $schema->slug . '`, `' . $bin . 'get ' . $schema->slug . '/<slug>`.';

        return new AgentSection('type:' . $schema->slug, $schema->label, implode("\n", $lines), types: [$schema->slug]);
    }

    private function workflow(): AgentSection
    {
        $bin = self::binary($this->context);

        return new AgentSection('workflow', 'Workflow', implode("\n", [
            '## Workflow',
            '',
            '1. `' . $bin . 'schema <type>` once, to see fields and options.',
            '2. `' . $bin . 'get <type>/<slug>` to read a record; edit the JSON; `' . $bin . 'put <type>/<slug> --if-revision:<n> < file.json` to write it back. Unchanged fields may be left out.',
            '3. For a new record: `' . $bin . 'put <type> < file.json` with at least the title field; the slug is derived from it.',
            '4. Images: `' . $bin . 'media:add photo.jpg --alt:"…"` first, then reference `{"file": …}` from the answer.',
            '5. `vendor/bin/freezed build` renders the site; `' . $bin . 'get <type>/<slug> --export` shows the variables a template receives.',
        ]));
    }

    private function rules(): AgentSection
    {
        $rules = [
            'Never approve: records of types with approval by a person (see the types) are published only in the desk UI. Desk refuses it from the CLI anyway; do not try to work around it.',
            'Never send: pushing the outbox to the server ("Senden") is a person\'s step in the UI.',
            'Never delete records or media unless you were told to; archive or leave a note instead.',
            'Never copy internal fields (contacts, notes) into any text.',
            'Use --if-revision on put and re-read the record on a conflict; never overwrite a person\'s change.',
        ];
        $approvalTypes = array_keys(array_filter($this->context->schemas()->all(), static fn (TypeSchema $s): bool => $s->needsUiApproval()));
        $lines = ['## What an agent does not do', ''];
        foreach ($rules as $rule) {
            $lines[] = '- ' . $rule;
        }
        if ($approvalTypes !== []) {
            $lines[] = '';
            $lines[] = 'Types with approval by a person: ' . implode(', ', array_map(static fn (string $t): string => '`' . $t . '`', $approvalTypes)) . '.';
        }

        return new AgentSection('rules', 'What an agent does not do', implode("\n", $lines), rules: $rules);
    }

    /**
     * desk/agent/*.md in alphabetical order.
     *
     * @return AgentSection[]
     */
    public function projectFiles(): array
    {
        $directory = $this->context->config->agentPath();
        $sections = [];
        $files = glob($directory . '/*.md') ?: [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $topic = basename($file, '.md');
            if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $topic)) {
                continue;
            }
            [$meta, $body] = self::frontMatter((string) file_get_contents($file));
            $title = preg_match('/^#\s+(.+)$/m', $body, $m) ? trim($m[1]) : $topic;
            $sections[] = new AgentSection('project:' . $topic, $title, $body, source: $this->relative($file), topics: [$topic], types: $meta['types'] ?? []);
        }

        return $sections;
    }

    /**
     * A minimal front matter reader: "key: value" and "key: [a, b]" lines
     * between two "---" lines.
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    public static function frontMatter(string $content): array
    {
        if (!preg_match('/\A---\s*\n(.*?)\n---\s*\n?/s', $content, $m)) {
            return [[], $content];
        }
        $meta = [];
        foreach (explode("\n", $m[1]) as $line) {
            if (!preg_match('/^\s*([A-Za-z][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $pair)) {
                continue;
            }
            $value = trim($pair[2]);
            if (preg_match('/^\[(.*)\]$/', $value, $list)) {
                $value = array_values(array_filter(array_map(static fn (string $v): string => trim($v, " \t'\""), explode(',', $list[1])), static fn (string $v): bool => $v !== ''));
            } else {
                $value = trim($value, "'\"");
            }
            $meta[$pair[1]] = $value;
        }

        return [$meta, substr($content, strlen($m[0]))];
    }

    private function describe(string $name, FieldDefinition $field): string
    {
        $parts = ['`' . $name . '` ' . $field->type];
        if ($field->required) {
            $parts[] = 'required';
        }
        if ($field->internal) {
            $parts[] = 'internal';
        }
        if ($field->system) {
            $parts[] = 'system (written by Desk)';
        }
        if ($name !== 'item') {
            $parts[] = '"' . $field->label . '"';
        }
        if ($field->help !== null && $name !== 'item') {
            $parts[] = 'help: ' . $field->help;
        }

        switch ($field->type) {
            case 'select':
                $type = $this->context->fieldTypes()->get('select');
                /** @var \Neuedaten\FreezedDesk\Schema\FieldTypes\SelectType $type */
                $options = $type->options($field);
                $parts[] = 'options: ' . implode(', ', array_map(static fn (string $k, string $l): string => '`' . $k . '` (' . $l . ')', array_keys($options), $options));
                if ($field->get('multiple')) {
                    $parts[] = 'multiple';
                }
                break;
            case 'relation':
                $parts[] = 'to: ' . implode(', ', array_map(static fn ($t): string => '`' . $t . '`', (array) $field->get('to')));
                if ($field->get('multiple')) {
                    $parts[] = 'multiple';
                }
                break;
            case 'features':
                $type = $this->context->fieldTypes()->get('features');
                if ($type instanceof FeaturesType) {
                    $keys = [];
                    foreach ($type->vocabulary($field, $this->context) as $key => $entry) {
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
                $subs[] = $this->describe($subName, $sub);
            }
            $line .= ' — fields: ' . implode('; ', $subs);
        }
        if ($field->of !== null) {
            $line .= ' — of: ' . $this->describe('item', $field->of);
        }

        return $line;
    }

    private function relative(string $path): string
    {
        $root = $this->context->config->projectRoot;

        return str_starts_with($path, $root) ? ltrim(substr($path, strlen($root)), '/') : $path;
    }
}
