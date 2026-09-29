<?php

namespace Mralston\Diagnostics\Executors;

use Mralston\Diagnostics\Contracts\Executor;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Finaliser;
use Mralston\Diagnostics\Runs\Runner;

/**
 * Runs every check in the calling process, in order, then finalises.
 */
class SyncExecutor implements Executor
{
    public function __construct(
        private readonly Runner $runner,
        private readonly Finaliser $finaliser,
    ) {
    }

    public function execute(DiagnosticRun $run): void
    {
        $results = $run->results()->where('status', ResultStatus::Pending->value)->orderBy('position')->get();

        foreach ($results as $result) {
            $this->runner->runCheck($run, $result);
        }

        $this->finaliser->finalise($run->refresh());
    }
}
