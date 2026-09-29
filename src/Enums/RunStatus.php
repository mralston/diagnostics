<?php

namespace Mralston\Diagnostics\Enums;

enum RunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Abandoned;
    }
}
