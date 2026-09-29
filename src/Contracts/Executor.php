<?php

namespace Mralston\Diagnostics\Contracts;

use Mralston\Diagnostics\Models\DiagnosticRun;

interface Executor
{
    /**
     * Execute every pending result of the run. A sync executor returns when
     * the run is complete; a queued executor returns once its jobs are
     * dispatched.
     */
    public function execute(DiagnosticRun $run): void;
}
