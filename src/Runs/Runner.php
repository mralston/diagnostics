<?php

namespace Mralston\Diagnostics\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Events\CheckCompleted;
use Mralston\Diagnostics\Events\CheckStarted;
use Mralston\Diagnostics\Finding;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Support\EventEmitter;
use Throwable;

/**
 * Executes one check and records it. The only place a result row moves from
 * pending to completed, whichever executor is driving, so every run leaves
 * the same rows and raises the same events.
 */
class Runner
{
    public function runCheck(DiagnosticRun $run, DiagnosticResult $result): DiagnosticResult
    {
        $startedAt = now();

        // Claim the result. A redelivered job finds it already claimed and moves on.
        $claimed = DiagnosticResult::whereKey($result->id)
            ->where('status', ResultStatus::Pending->value)
            ->update(['status' => ResultStatus::Running->value, 'started_at' => $startedAt]);

        if ($claimed !== 1) {
            return $result->refresh();
        }

        DiagnosticRun::whereKey($run->id)
            ->where('status', RunStatus::Pending->value)
            ->update(['status' => RunStatus::Running->value, 'started_at' => $startedAt]);

        $result->forceFill(['status' => ResultStatus::Running, 'started_at' => $startedAt]);

        EventEmitter::emit(new CheckStarted($run, $result));

        $started = hrtime(true);
        $error = null;
        $subject = null;

        try {
            $suite = $run->suiteDefinition();
            $subject = $suite->findSubject($run->subject_id);

            if ($subject === null) {
                throw new \RuntimeException("Subject {$run->subject_type} #{$run->subject_id} could not be loaded.");
            }

            $check = app($result->check_class);
            $outcome = $check->run($subject);

            if (! $outcome instanceof Result) {
                throw new \LogicException(sprintf('%s::run() must return %s, %s returned.', $result->check_class, Result::class, get_debug_type($outcome)));
            }
        } catch (Throwable $e) {
            $outcome = Result::fail();
            $error = $e::class.': '.$e->getMessage();

            Log::error('Diagnostics check threw.', [
                'run' => $run->id,
                'check' => $result->check_class,
                'exception' => $e,
            ]);
        }

        $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

        $findings = $outcome->getFindings();
        $finalOutcome = $error !== null ? Outcome::Errored : $outcome->outcome;
        $downgraded = false;

        if ($finalOutcome === Outcome::Failed && ! $result->can_fail) {
            $finalOutcome = Outcome::Warning;
            $downgraded = true;
        }

        if ($subject instanceof Model && $subject->isDirty()) {
            $findings[] = new Finding('The check changed the record without saving it. Checks must be read-only.', ['dirty' => array_keys($subject->getDirty())]);
            Log::warning('Diagnostics check mutated its subject.', ['run' => $run->id, 'check' => $result->check_class]);
        }

        $timeout = ($result->timeout ?? null) ?: (int) config('diagnostics.check_timeout', 60);
        if ($timeout > 0 && $durationMs > $timeout * 1000) {
            Log::warning('Diagnostics check exceeded its advisory timeout.', ['run' => $run->id, 'check' => $result->check_class, 'duration_ms' => $durationMs]);
        }

        $result->forceFill([
            'status' => ResultStatus::Completed,
            'outcome' => $finalOutcome,
            'downgraded' => $downgraded,
            'summary' => $error !== null ? ($outcome->summary ?? 'The check could not be completed.') : $outcome->summary,
            'findings' => $this->limitFindings($findings),
            'error' => $error,
            'finished_at' => now(),
            'duration_ms' => $durationMs,
        ])->save();

        $column = $finalOutcome->countColumn();

        DiagnosticRun::whereKey($run->id)->update([
            $column => DB::raw("{$column} + 1"),
            'last_activity_at' => now(),
        ]);

        $run->refresh();

        EventEmitter::emit(new CheckCompleted($run, $result));

        return $result;
    }

    /**
     * @param  Finding[]  $findings
     * @return array<int, array{message: string, data: array}>
     */
    private function limitFindings(array $findings): array
    {
        $max = (int) config('diagnostics.limits.max_findings_per_result', 200);
        $maxBytes = (int) config('diagnostics.limits.max_findings_bytes', 65536);

        $rows = array_map(fn (Finding $f) => $f->toArray(), array_slice($findings, 0, $max));

        while (count($rows) > 1 && strlen((string) json_encode($rows)) > $maxBytes) {
            array_pop($rows);
        }

        return $rows;
    }
}
