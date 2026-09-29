<?php

namespace Mralston\Diagnostics\Runs;

use Closure;
use Illuminate\Support\Facades\DB;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Models\DiagnosticRun;

/**
 * Moves a queued run between phases as batches finish. Each transition is
 * claimed with one conditional UPDATE, so of all the workers that finish
 * around the same moment exactly one performs it, on any database, with no
 * batch table and no locks.
 */
class PhaseAdvancer
{
    public function __construct(private readonly Finaliser $finaliser)
    {
    }

    /**
     * @param  Closure(list<int> $resultIds): void  $dispatchSerial
     */
    public function batchFinished(DiagnosticRun $run, Closure $dispatchSerial): void
    {
        DiagnosticRun::whereKey($run->id)->update([
            'pending_batches' => DB::raw('pending_batches - 1'),
            'last_activity_at' => now(),
        ]);

        if ($this->claim($run, Phase::Parallel, Phase::Serial)) {
            $serialIds = $run->results()
                ->where('parallel_safe', false)
                ->where('status', ResultStatus::Pending->value)
                ->orderBy('position')
                ->pluck('id')
                ->all();

            if ($serialIds !== []) {
                DiagnosticRun::whereKey($run->id)->update(['pending_batches' => 1]);
                $dispatchSerial($serialIds);

                return;
            }

            DiagnosticRun::whereKey($run->id)->update(['phase' => Phase::Finalising->value]);
            $this->finaliser->finalise($run->refresh());

            return;
        }

        if ($this->claim($run, Phase::Serial, Phase::Finalising)) {
            $this->finaliser->finalise($run->refresh());
        }
    }

    private function claim(DiagnosticRun $run, Phase $from, Phase $to): bool
    {
        return DiagnosticRun::whereKey($run->id)
            ->where('phase', $from->value)
            ->where('pending_batches', '<=', 0)
            ->update(['phase' => $to->value]) === 1;
    }
}
