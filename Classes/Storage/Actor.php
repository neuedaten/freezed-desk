<?php

namespace Neuedaten\FreezedDesk\Storage;

/**
 * Who changed a record. The UI always writes as "editor"; the CLI writes as
 * "cli" or, with DESK_ACTOR=agent / --actor:agent, as "agent"; import and
 * seed write as "import". Only an editor can approve a type with
 * approval: 'ui' (docs/approval.md), so the CLI never accepts "editor".
 */
enum Actor: string
{
    case Editor = 'editor';
    case Cli = 'cli';
    case Agent = 'agent';
    case Import = 'import';

    /** Actors the CLI may take with --actor: or DESK_ACTOR. */
    public const CLI_CHOICES = ['cli', 'agent'];

    public function isHuman(): bool
    {
        return $this === self::Editor;
    }
}
