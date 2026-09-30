<?php

namespace Mralston\Diagnostics\Fixes;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Enums\FixStatus;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Events\CheckFixed;
use Mralston\Diagnostics\Exceptions\FixFailed;
use Mralston\Diagnostics\Exceptions\FixUnavailable;
use Mralston\Diagnostics\Models\DiagnosticFix;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Runs\Runner;
use Throwable;

/**
 * Applies a check's fix to the subject of a finished result, then re-checks.
 *
 * The answers are validated against the check's questions before the fix is
 * called. The fix and the suite's afterFix callback share one transaction, so
 * a fix that stops or throws leaves the record as it was. Every attempt is
 * recorded, and a successful one marks the run as out of date, because only
 * the fixed check has looked at the record since.
 */
class Fixer
{
    public function __construct(private readonly Runner $runner)
    {
    }

    /**
     * The questions to ask before fixing this result, resolved against the
     * record as it is now.
     *
     * @return Question[]
     */
    public function questions(DiagnosticResult $result): array
    {
        [, $subject, $check] = $this->resolve($result);

        return $check->resolvedFixQuestions($subject);
    }

    public function description(DiagnosticResult $result): ?string
    {
        [, $subject, $check] = $this->resolve($result);

        return $check->fixDescription($subject);
    }

    /**
     * @param  array<string, mixed>  $answers  keyed by question name
     *
     * @throws FixUnavailable when no fix is on offer for this result
     * @throws \Illuminate\Validation\ValidationException when the answers do not satisfy the questions
     */
    public function fix(DiagnosticResult $result, array $answers = [], mixed $user = null): DiagnosticFix
    {
        [$suite, $subject, $check] = $this->resolve($result);

        $questions = $check->resolvedFixQuestions($subject);
        $answers = $this->validate($questions, $answers);

        $lock = Cache::lock("diagnostics:fix:{$result->id}", 60);

        if (! $lock->get()) {
            throw new FixUnavailable('This fix is already being applied.');
        }

        try {
            $run = $result->run;
            $before = $result->outcome;
            $started = hrtime(true);
            $status = FixStatus::Succeeded;
            $message = null;
            $error = null;

            try {
                $message = DB::transaction(function () use ($suite, $subject, $check, $result, $answers) {
                    $message = $check->fix($subject, $answers);

                    $suite->runAfterFix($suite->findSubject((string) $subject->getKey()) ?? $subject, $result, $answers);

                    return is_string($message) ? $message : null;
                });
            } catch (FixFailed $e) {
                $status = FixStatus::Failed;
                $message = $e->getMessage();
            } catch (Throwable $e) {
                $status = FixStatus::Errored;
                $message = 'The fix could not be applied.';
                $error = $e::class.': '.$e->getMessage();

                Log::error('Diagnostics fix threw.', [
                    'run' => $run->id,
                    'check' => $result->check_class,
                    'exception' => $e,
                ]);
            }

            $durationMs = (int) ((hrtime(true) - $started) / 1_000_000);

            if ($status === FixStatus::Succeeded) {
                $now = now();
                $run->forceFill(['fixed_at' => $now])->save();
                $this->runner->recheck($run, $result);
                $result->forceFill(['fixed_at' => $now])->save();
            }

            $fix = DiagnosticFix::create([
                'run_id' => $run->id,
                'result_id' => $result->id,
                'check_class' => $result->check_class,
                'status' => $status,
                'outcome_before' => $before,
                'outcome_after' => $result->outcome,
                'answers' => $answers === [] ? null : $answers,
                'message' => $message,
                'error' => $error,
                'fixed_by' => $this->userKey($user),
                'duration_ms' => $durationMs,
            ]);

            event(new CheckFixed($fix));

            return $fix->setRelation('result', $result);
        } finally {
            $lock->release();
        }
    }

    /** @return array{0: \Mralston\Diagnostics\Suite, 1: \Illuminate\Database\Eloquent\Model, 2: Check} */
    private function resolve(DiagnosticResult $result): array
    {
        $run = $result->run;

        if ($run->status !== RunStatus::Completed) {
            throw new FixUnavailable('Fixes are available once the run has finished.');
        }

        if (! $result->fixable) {
            throw new FixUnavailable('There is no fix on offer for this result.');
        }

        if (! class_exists($result->check_class)) {
            throw new FixUnavailable('This check no longer exists.');
        }

        $check = app($result->check_class);

        if (! $check instanceof Check || ! $check->isFixable()) {
            throw new FixUnavailable('This check no longer has a fix.');
        }

        $suite = $run->suiteDefinition();
        $subject = $suite->findSubject($run->subject_id);

        if ($subject === null) {
            throw new FixUnavailable('The record could not be loaded.');
        }

        return [$suite, $subject, $check];
    }

    /**
     * @param  Question[]  $questions
     * @return array<string, mixed>
     */
    private function validate(array $questions, array $answers): array
    {
        if ($questions === []) {
            return [];
        }

        $rules = [];
        $attributes = [];

        foreach ($questions as $question) {
            $rules[$question->name] = $question->validationRules();
            $attributes[$question->name] = $question->label;
        }

        $valid = Validator::make($answers, $rules, [], $attributes)->validate();

        $cast = [];
        foreach ($questions as $question) {
            $cast[$question->name] = $question->cast($valid[$question->name] ?? null);
        }

        return $cast;
    }

    private function userKey(mixed $user): ?string
    {
        if ($user instanceof Authenticatable && $user->getAuthIdentifier() !== null) {
            return (string) $user->getAuthIdentifier();
        }

        return is_scalar($user) ? (string) $user : null;
    }
}
