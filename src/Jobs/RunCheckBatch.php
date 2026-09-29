<?php

namespace Mralston\Diagnostics\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\PhaseAdvancer;
use Mralston\Diagnostics\Runs\Runner;
use Throwable;

/**
 * One batch of a queued run. Carries only ids, so nothing about the subject
 * is serialised onto the queue.
 */
class RunCheckBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public int $timeout;

    /**
     * @param  list<int>  $resultIds
     */
    public function __construct(
        public readonly int $runId,
        public readonly array $resultIds,
    ) {
        $perCheck = (int) config('diagnostics.check_timeout', 60);
        $cap = (int) config('diagnostics.batch_timeout_cap', 900);

        $this->timeout = max(30, min($cap, $perCheck * max(1, count($resultIds))));
    }

    /** Dispatch on the suite's connection and queue. */
    public static function dispatchFor(DiagnosticRun $run, array $resultIds): void
    {
        $suite = $run->suiteDefinition();

        $job = new static($run->id, $resultIds);

        if ($suite->queueConnection() !== null) {
            $job->onConnection($suite->queueConnection());
        }

        if ($suite->queueName() !== null) {
            $job->onQueue($suite->queueName());
        }

        dispatch($job);
    }

    public function handle(Runner $runner, PhaseAdvancer $advancer): void
    {
        $run = DiagnosticRun::find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        foreach ($this->resultIds as $id) {
            $result = DiagnosticResult::find($id);

            if ($result !== null) {
                $runner->runCheck($run, $result);
            }
        }

        $advancer->batchFinished($run, fn (array $ids) => static::dispatchFor($run, $ids));
    }

    /**
     * The worker died or timed out mid-batch. Whatever this batch had not
     * finished is recorded as errored, and the run moves on as if the batch
     * had completed, so the other batches can still close it.
     */
    public function failed(?Throwable $exception): void
    {
        $run = DiagnosticRun::find($this->runId);

        if ($run === null || $run->isTerminal()) {
            return;
        }

        $unfinished = DiagnosticResult::whereIn('id', $this->resultIds)
            ->whereIn('status', [ResultStatus::Pending->value, ResultStatus::Running->value])
            ->count();

        if ($unfinished > 0) {
            DiagnosticResult::whereIn('id', $this->resultIds)
                ->whereIn('status', [ResultStatus::Pending->value, ResultStatus::Running->value])
                ->update([
                    'status' => ResultStatus::Completed->value,
                    'outcome' => Outcome::Errored->value,
                    'summary' => 'The worker running this check was lost.',
                    'error' => $exception ? $exception::class.': '.$exception->getMessage() : 'Worker lost.',
                    'finished_at' => now(),
                ]);

            DiagnosticRun::whereKey($run->id)->update([
                Outcome::Errored->countColumn() => DB::raw(Outcome::Errored->countColumn()." + {$unfinished}"),
            ]);
        }

        app(PhaseAdvancer::class)->batchFinished($run, fn (array $ids) => static::dispatchFor($run, $ids));
    }
}
