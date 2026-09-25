<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Storage\Status;

class ArchiveCommand extends StatusCommand
{
    protected function status(): Status
    {
        return Status::Archived;
    }
}
