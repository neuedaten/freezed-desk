<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Storage\Status;

class UnpublishCommand extends StatusCommand
{
    protected function status(): Status
    {
        return Status::Draft;
    }
}
