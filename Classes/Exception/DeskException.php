<?php

namespace Neuedaten\FreezedDesk\Exception;

/**
 * Base class of everything Desk throws on purpose: a misconfiguration, an
 * invalid schema, a record that fails validation. The CLI prints the message
 * and exits with 1; the web UI shows it.
 */
class DeskException extends \RuntimeException
{
}
