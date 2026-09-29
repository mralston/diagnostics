<?php

namespace Mralston\Diagnostics\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mralston\Diagnostics\Discovery\CheckDefinition;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Events\RunStarted;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Suite;
use Mralston\Diagnostics\Support\EventEmitter;

/**
 * Creates the run row and every result row up front, all pending, so a UI
 * can draw the whole list before a single check has run.
 */
class RunFactory
{
    public function create(Suite $suite, Model $subject, Executor $executor, Trigger $trigger, ?string $triggeredById = null): DiagnosticRun
    {
        $checks = $suite->checks();
        $now = now();

        $run = DB::transaction(function () use ($suite, $subject, $executor, $trigger, $triggeredById, $checks, $now) {
            $run = DiagnosticRun::create([
                'suite' => $suite->getKey(),
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => (string) $subject->getKey(),
                'status' => RunStatus::Pending,
                'phase' => Phase::Parallel,
                'executor' => $executor,
                'triggered_by' => $trigger,
                'triggered_by_id' => $triggeredById,
                'total_checks' => $checks->count(),
                'pending_batches' => 0,
                'subject_updated_at' => $this->subjectUpdatedAt($subject),
                'last_activity_at' => $now,
            ]);

            $rows = $checks->map(fn (CheckDefinition $check) => [
                'run_id' => $run->id,
                'check_class' => $check->class,
                'title' => $check->title,
                'category' => $check->category,
                'position' => $check->position,
                'parallel_safe' => $check->parallelSafe,
                'can_fail' => $check->canFail,
                'status' => ResultStatus::Pending->value,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($rows, 100) as $chunk) {
                $run->results()->insert($chunk);
            }

            return $run;
        });

        $run->load('results');

        EventEmitter::emit(new RunStarted($run));

        return $run;
    }

    private function subjectUpdatedAt(Model $subject): mixed
    {
        if (! $subject->usesTimestamps() || $subject->getUpdatedAtColumn() === null) {
            return null;
        }

        return $subject->{$subject->getUpdatedAtColumn()};
    }
}
