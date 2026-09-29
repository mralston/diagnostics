<?php

namespace Mralston\Diagnostics;

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

        return new Gate($definition, $subject, $run, (array) config('diagnostics.chain.acceptable', ['passed', 'passed_with_warnings']));
    }

    public function dispatcher(): Dispatcher
    {
        return app(Dispatcher::class);
    }
}
