<?php

namespace Mralston\Diagnostics\Runs;

use Mralston\Diagnostics\Models\DiagnosticRun;

final class StartedRun
{
    public function __construct(
        public readonly DiagnosticRun $run,
        public readonly bool $coalesced,
    ) {
    }
}
