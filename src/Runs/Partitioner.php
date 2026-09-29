<?php

namespace Mralston\Diagnostics\Runs;

use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;

/**
 * Deals the pending results into parallel batches, round-robin so each batch
 * takes a similar mix of categories, and sets aside the serial ones.
 */
class Partitioner
{
    /**
     * @return array{parallel: list<list<int>>, serial: list<int>}
     */
    public function partition(DiagnosticRun $run, int $batches): array
    {
        $batches = max(1, $batches);

        $results = $run->results()
            ->where('status', ResultStatus::Pending->value)
            ->orderBy('position')
            ->get(['id', 'parallel_safe']);

        $parallel = [];
        $serial = [];
        $i = 0;

        foreach ($results as $result) {
            /** @var DiagnosticResult $result */
            if ($result->parallel_safe) {
                $parallel[$i % $batches][] = $result->id;
                $i++;
            } else {
                $serial[] = $result->id;
            }
        }

        $parallel = array_values(array_filter($parallel));

        foreach ($parallel as $index => $ids) {
            DiagnosticResult::whereIn('id', $ids)->update(['batch' => $index + 1]);
        }

        if ($serial !== []) {
            DiagnosticResult::whereIn('id', $serial)->update(['batch' => 0]);
        }

        return ['parallel' => $parallel, 'serial' => $serial];
    }
}
