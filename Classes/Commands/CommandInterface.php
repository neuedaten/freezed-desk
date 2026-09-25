<?php

namespace Neuedaten\FreezedDesk\Commands;

/**
 * Desk commands are core commands: registered through composer.json
 * (extra.freezed.commands), so `freezed desk:show` and `freezed-desk show`
 * are the same thing.
 */
interface CommandInterface extends \Neuedaten\Freezed\Commands\CommandInterface
{
}
