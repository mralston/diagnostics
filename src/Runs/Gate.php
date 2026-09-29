<?php

namespace Mralston\Diagnostics\Runs;

use Illuminate\Database\Eloquent\Model;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Suite;

/**
 * Answers "may this subject proceed?" from its latest completed run: the run
 * must be fresh and its outcome acceptable.
 */
final class Gate
{
    /** @param  string[]  $acceptable */
    public function __construct(
        private readonly Suite $suite,
        private readonly Model $subject,
        private readonly ?DiagnosticRun $run,
        private readonly array $acceptable,
    ) {
    }

    public function run(): ?DiagnosticRun
    {
        return $this->run;
    }

    public function suite(): Suite
    {
        return $this->suite;
    }

    public function isFresh(): bool
    {
        return $this->run !== null && $this->run->isFreshFor($this->subject);
    }

    public function passed(): bool
    {
        return $this->isFresh()
            && $this->run?->outcome !== null
            && in_array($this->run->outcome->value, $this->acceptable, true);
    }

    /** Why the gate is closed, or null when it is open. */
    public function reason(): ?string
    {
        if ($this->run === null) {
            return sprintf('%s has not been run yet.', $this->suite->getLabel());
        }

        if (($reason = $this->run->stalenessReason($this->subject)) !== null) {
            return $reason;
        }

        if (! $this->passed()) {
            return sprintf('The last run finished with the outcome "%s".', $this->run->outcome?->label() ?? 'unknown');
        }

        return null;
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed(),
            'fresh' => $this->isFresh(),
            'reason' => $this->reason(),
            'run_id' => $this->run?->id,
        ];
    }
}
