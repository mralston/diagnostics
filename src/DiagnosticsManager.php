<?php

namespace Mralston\Diagnostics;

use Mralston\Diagnostics\Fixes\Fixer;
use Mralston\Diagnostics\Models\DiagnosticFix;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Illuminate\Database\Eloquent\Model;
use Mralston\Diagnostics\Data\RunStatusData;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Exceptions\UnknownSuite;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Dispatcher;
use Mralston\Diagnostics\Runs\Gate;

/**
 * The registry of suites and the entry point for starting runs and asking
 * where a subject stands. Bound as a singleton; reached through the facade.
 */
class DiagnosticsManager
{
    /** @var array<string, Suite> */
    private array $suites = [];

    /** The registered suite with this key, or a new one ready to be built and registered. */
    public function suite(string $key): Suite
    {
        return $this->suites[$key] ?? new Suite($key);
    }

    public function register(Suite $suite): void
    {
        $this->suites[$suite->getKey()] = $suite;
    }

    public function has(string $key): bool
    {
        return isset($this->suites[$key]);
    }

    public function get(string $key): Suite
    {
        return $this->suites[$key] ?? throw UnknownSuite::named($key);
    }

    /** @return array<string, Suite> */
    public function suites(): array
    {
        return $this->suites;
    }

    /**
     * The suite for a subject when its class has exactly one. Name the suite
     * where a class has several.
     */
    public function suiteFor(Model|string $subject): Suite
    {
        $class = is_string($subject) ? $subject : $subject::class;

        $matches = array_filter($this->suites, fn (Suite $suite) => is_a($class, $suite->getSubjectClass(), true));

        return match (count($matches)) {
            0 => throw UnknownSuite::forSubject($class),
            1 => reset($matches),
            default => throw UnknownSuite::ambiguous($class, array_keys($matches)),
        };
    }

    public function resolve(Model $subject, ?string $suite): Suite
    {
        return $suite === null ? $this->suiteFor($subject) : $this->get($suite);
    }

    // -- Starting runs ------------------------------------------------------------------------

    /** Start a run using the configured default executor. */
    public function start(Model $subject, ?string $suite = null, Trigger $trigger = Trigger::Code, ?string $triggeredById = null): DiagnosticRun
    {
        $executor = Executor::tryFrom((string) config('diagnostics.default_executor', 'queued')) ?? Executor::Queued;

        return $this->dispatcher()->start($this->resolve($subject, $suite), $subject, $executor, $trigger, $triggeredById)->run;
    }

    /** Run every check in this process and return the finished run. */
    public function run(Model $subject, ?string $suite = null, Trigger $trigger = Trigger::Code, ?string $triggeredById = null): DiagnosticRun
    {
        return $this->dispatcher()->start($this->resolve($subject, $suite), $subject, Executor::Sync, $trigger, $triggeredById)->run;
    }

    /** Fan the checks out to the queue and return the pending run. */
    public function dispatch(Model $subject, ?string $suite = null, Trigger $trigger = Trigger::Job, ?string $triggeredById = null): DiagnosticRun
    {
        return $this->dispatcher()->start($this->resolve($subject, $suite), $subject, Executor::Queued, $trigger, $triggeredById)->run;
    }

    // -- Reading ------------------------------------------------------------------------------

    public function latestRun(Model $subject, ?string $suite = null): ?DiagnosticRun
    {
        return DiagnosticRun::forSubject($subject)
            ->suite($this->resolve($subject, $suite)->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * The latest run for each of several subjects of one class, in a single query, keyed by
     * subject key. Subjects that have never been run are absent. For lists and tables.
     *
     * @param  iterable<Model>|\Illuminate\Contracts\Pagination\Paginator  $subjects
     * @return \Illuminate\Support\Collection<string, DiagnosticRun>
     */
    public function latestRuns(iterable $subjects, ?string $suite = null): \Illuminate\Support\Collection
    {
        // collect() on a paginator turns its models into arrays, so take its items instead.
        if ($subjects instanceof \Illuminate\Contracts\Pagination\Paginator) {
            $subjects = $subjects->items();
        }

        $subjects = collect($subjects)->filter(fn ($subject) => $subject instanceof Model)->values();

        if ($subjects->isEmpty()) {
            return collect();
        }

        $first = $subjects->first();
        $definition = $this->resolve($first, $suite);
        $ids = $subjects->map(fn (Model $subject) => (string) $subject->getKey())->unique()->values()->all();

        $latestIds = DiagnosticRun::query()
            ->selectRaw('max(id) as id')
            ->where('suite', $definition->getKey())
            ->where('subject_type', $first->getMorphClass())
            ->whereIn('subject_id', $ids)
            ->groupBy('subject_id');

        return DiagnosticRun::query()
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy(fn (DiagnosticRun $run) => (string) $run->subject_id);
    }

    public function inFlight(Model $subject, ?string $suite = null): ?DiagnosticRun
    {
        return DiagnosticRun::forSubject($subject)
            ->suite($this->resolve($subject, $suite)->getKey())
            ->inFlight()
            ->latest('id')
            ->first();
    }

    public function status(Model $subject, ?string $suite = null): ?RunStatusData
    {
        $run = $this->latestRun($subject, $suite);

        return $run === null ? null : RunStatusData::fromRun($run, $subject);
    }

    public function gate(Model $subject, ?string $suite = null): Gate
    {
        $definition = $this->resolve($subject, $suite);

        $run = DiagnosticRun::forSubject($subject)
            ->suite($definition->getKey())
            ->completed()
            ->latest('id')
            ->first();

        return new Gate($definition, $subject, $run, $definition->acceptableOutcomes());
    }

    /**
     * Applies the fix behind a finished result and re-checks it. See Fixer.
     *
     * @param  array<string, mixed>  $answers  keyed by question name
     */
    public function fix(DiagnosticResult $result, array $answers = [], mixed $user = null): DiagnosticFix
    {
        return app(Fixer::class)->fix($result, $answers, $user);
    }

    public function dispatcher(): Dispatcher
    {
        return app(Dispatcher::class);
    }
}
