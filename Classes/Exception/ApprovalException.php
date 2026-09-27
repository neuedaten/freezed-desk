<?php

namespace Neuedaten\FreezedDesk\Exception;

/**
 * Publishing a record of a type with approval: 'ui' from anywhere but the
 * UI (docs/approval.md). The record stays as it was.
 */
final class ApprovalException extends DeskException
{
}
