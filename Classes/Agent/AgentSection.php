<?php

namespace Neuedaten\FreezedDesk\Agent;

/**
 * One section of the agent guide (A2): generated from the schema, written
 * by an extension, or read from the project's desk/agent/<topic>.md.
 *
 * $topics say for which `desk:agent <topic>` the section is printed besides
 * the full guide; $types which types it is about (their generated
 * description joins a topic guide). $commands and $rules feed the
 * structured form (`desk:agent --json`, A2.5).
 */
final readonly class AgentSection
{
    /**
     * @param string[] $topics
     * @param string[] $types
     * @param array<int, array{command: string, description: string}> $commands
     * @param string[] $rules
     */
    public function __construct(
        public string $id,
        public string $title,
        public string $markdown,
        public string $source = 'desk',
        public array $topics = [],
        public array $types = [],
        public array $commands = [],
        public array $rules = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'source' => $this->source,
            'topics' => $this->topics,
            'types' => $this->types,
            'commands' => $this->commands,
            'rules' => $this->rules,
            'markdown' => $this->markdown,
        ];
    }
}
