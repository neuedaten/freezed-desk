<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Cli;
use Neuedaten\FreezedDesk\Agent\AgentGuide;
use Neuedaten\FreezedDesk\DeskContext;

/**
 * `freezed-desk agent [<topic>] [--json]` — a guide to this project's desk,
 * written for an agent (A2): the part generated from the schema, the
 * sections of Desk's modules and packages, and the project's own files in
 * desk/agent/. With a topic, only desk/agent/<topic>.md and what belongs to
 * it; with --json the same as structured data. `--topics` lists the topics.
 */
class AgentCommand extends AbstractCommand
{
    protected function answersInJson(): bool
    {
        return false;
    }

    protected function run(DeskContext $context, array $args, array $options): int
    {
        $guide = new AgentGuide($context);

        if (Options::flag($options, 'topics')) {
            self::printJson(['topics' => $guide->topics()]);

            return 0;
        }

        $sections = isset($args[0]) ? $guide->topic($args[0]) : $guide->sections();

        if (Options::flag($options, 'json')) {
            self::printJson(AgentGuide::structured($sections));

            return 0;
        }

        fwrite(Cli::out(), AgentGuide::markdown($sections));

        return 0;
    }
}
