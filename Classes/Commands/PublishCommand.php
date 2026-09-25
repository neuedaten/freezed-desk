<?php

namespace Neuedaten\FreezedDesk\Commands;

use Neuedaten\FreezedDesk\Storage\Status;

class PublishCommand extends StatusCommand
{
    protected function status(): Status
    {
        return Status::Published;
    }
}
