<?php

namespace Mralston\Diagnostics\Runs;

use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Events\RunCompleted;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Support\EventEmitter;

/**
 * Closes a run: anything never executed is recorded as errored, the counts
 * are recomputed from the rows (the authoritative record), the outcome is
 * derived, and RunCompleted goes out.
 */
class Finaliser
{
    public function finalise(DiagnosticRun $run, RunStatus $status = RunStatus::Completed, ?string $unfinishedReason = null): DiagnosticRun
    {
        $run->results()
            ->whereIn('status', [ResultStatus::Pending->value, ResultStatus::Running->value])
            ->update([
                'status' => ResultStatus::Completed->value,
                'outcome' => Outcome::Errored->value,
                'summary' => 'The check did not run.',
                'error' => $unfinishedReason ?? 'The run finished before this check was executed.',
                'finished_at' => now(),
            ]);

        // reorder() drops the relation's ORDER BY position, which MySQL's
        // only_full_group_by mode rejects alongside GROUP BY outcome.
        $counts = $run->results()
            ->reorder()
            ->selectRaw('outcome, count(*) as aggregate')
            ->groupBy('outcome')
            ->pluck('aggregate', 'outcome');

        $attributes = [];
        foreach (Outcome::cases() as $outcome) {
            $attributes[$outcome->countColumn()] = (int) ($counts[$outcome->value] ?? 0);
        }

        $finishedAt = now();
        $startedAt = $run->started_at ?? $run->created_at ?? $finishedAt;

        $run->forceFill($attributes + [
            'status' => $status,
            'phase' => Phase::Done,
            'outcome' => RunOutcome::fromCounts(
                $attributes[Outcome::Failed->countColumn()],
                $attributes[Outcome::Errored->countColumn()],
                $attributes[Outcome::Warning->countColumn()],
            ),
            'pending_batches' => 0,
            'started_at' => $run->started_at ?? $startedAt,
            'finished_at' => $finishedAt,
            'duration_ms' => max(0, (int) $startedAt->diffInMilliseconds($finishedAt)),
            'last_activity_at' => $finishedAt,
        ])->save();

        EventEmitter::emit(new RunCompleted($run));

        return $run;
    }
}
