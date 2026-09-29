<?php

namespace Mralston\Diagnostics\Executors;

use Mralston\Diagnostics\Contracts\Executor;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Jobs\RunCheckBatch;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Finaliser;
use Mralston\Diagnostics\Runs\Partitioner;

/**
 * Splits the run into batches and dispatches them. The pending batch count is
 * written before the first dispatch, because under the sync queue driver a
 * dispatched job runs to completion inside dispatch().
 */
class QueuedExecutor implements Executor
{
    public function __construct(
        private readonly Partitioner $partitioner,
        private readonly Finaliser $finaliser,
    ) {
    }

    public function execute(DiagnosticRun $run): void
    {
        $suite = $run->suiteDefinition();
        ['parallel' => $parallel, 'serial' => $serial] = $this->partitioner->partition($run, $suite->parallelBatchCount());

        if ($parallel === [] && $serial === []) {
            $this->finaliser->finalise($run);

            return;
        }

        if ($parallel === []) {
            DiagnosticRun::whereKey($run->id)->update(['phase' => Phase::Serial->value, 'pending_batches' => 1]);
            RunCheckBatch::dispatchFor($run, $serial);

            return;
        }

        DiagnosticRun::whereKey($run->id)->update(['phase' => Phase::Parallel->value, 'pending_batches' => count($parallel)]);

        foreach ($parallel as $ids) {
            RunCheckBatch::dispatchFor($run, $ids);
        }
    }
}
