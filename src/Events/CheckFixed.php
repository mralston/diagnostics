<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mralston\Diagnostics\Models\DiagnosticFix;

/**
 * Raised after every fix attempt, whatever its outcome, for host listeners
 * such as an audit log. Not broadcast.
 */
class CheckFixed
{
    use Dispatchable;

    public function __construct(public readonly DiagnosticFix $fix)
    {
    }
}
