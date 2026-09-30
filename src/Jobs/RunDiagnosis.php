<?php

namespace Mralston\Diagnostics\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Exceptions\DiagnosisFailed;
use Mralston\Diagnostics\Exceptions\SubjectNotFound;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Dispatcher;

/**
 * A run as a queued job, and in particular as a step in Bus::chain(). It
 * executes in-process so that it has finished before the next job in the
 * chain is released, and throws when the outcome is not acceptable, which is
 * how a chain is stopped.
 *
 *   Bus::chain([
 *       new RunDiagnosis('listing-check', $listing),
 *       new PublishListing($listing),
 *   ])->dispatch();
 */
class RunDiagnosis implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public ?DiagnosticRun $run = null;

    public readonly string $subjectClass;

    public readonly string $subjectId;

    /**
     * @param  string|null  $suite  The suite key, or null when the subject class has only one suite.
     * @param  Model|string|int  $subject  The model, or its key when $suite is given.
     * @param  bool  $requirePass  Throw DiagnosisFailed unless the outcome is acceptable.
     * @param  string[]|null  $acceptable  Outcomes that count as a pass; defaults to the suite's.
     * @param  bool  $reuseFresh  Skip the run when a fresh acceptable run already exists.
     */
    public function __construct(
        public readonly ?string $suite,
        Model|string|int $subject,
        public readonly bool $requirePass = true,
        public readonly ?array $acceptable = null,
        public readonly bool $reuseFresh = false,
    ) {
        if ($subject instanceof Model) {
            $this->subjectClass = $subject::class;
            $this->subjectId = (string) $subject->getKey();
        } else {
            if ($suite === null) {
                throw new \InvalidArgumentException('Name the suite when passing a subject id rather than a model.');
            }

            $this->subjectClass = app(DiagnosticsManager::class)->get($suite)->getSubjectClass();
            $this->subjectId = (string) $subject;
        }

        $this->timeout = (int) config('diagnostics.chain.timeout', 600);
    }

    public function handle(DiagnosticsManager $manager, Dispatcher $dispatcher): void
    {
        $suite = $this->suite !== null ? $manager->get($this->suite) : $manager->suiteFor($this->subjectClass);
        $subject = $suite->findSubject($this->subjectId) ?? throw SubjectNotFound::for($suite->getKey(), $this->subjectId);
        $acceptable = $this->acceptable ?? $suite->acceptableOutcomes();

        if ($this->reuseFresh) {
            $gate = $manager->gate($subject, $suite->getKey());

            if ($gate->passed()) {
                $this->run = $gate->run();

                return;
            }
        }

        $this->run = $dispatcher->start($suite, $subject, Executor::Sync, Trigger::Chain, $this->job?->getJobId())->run;

        if ($this->requirePass && ! in_array($this->run->outcome?->value, $acceptable, true)) {
            throw new DiagnosisFailed($this->run);
        }
    }
}
