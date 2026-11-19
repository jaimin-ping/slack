<?php

namespace App\Ai\Exceptions;

use RuntimeException;

/**
 * The agent may not continue: it was paused or deactivated mid-run, its owner left the workspace,
 * or it went over a budget. Work stops before the next action; nothing already saved is undone.
 */
class AgentHalted extends RuntimeException
{
    public function __construct(string $message, public readonly string $runStatus = 'cancelled')
    {
        parent::__construct($message);
    }
}
