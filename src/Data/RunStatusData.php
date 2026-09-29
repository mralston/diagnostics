<?php

namespace Mralston\Diagnostics\Data;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Models\DiagnosticRun;

/**
 * A snapshot of where a subject's latest run stands, for code that wants an
 * answer without the model.
 */
final class RunStatusData implements Arrayable
{
    /**
     * @param  array<string, int>  $counts
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $suite,
        public readonly RunStatus $status,
        public readonly ?RunOutcome $outcome,
        public readonly array $counts,
        public readonly int $completed,
        public readonly int $total,
        public readonly ?string $startedAt,
        public readonly ?string $finishedAt,
        public readonly bool $fresh,
        public readonly ?string $stalenessReason,
    ) {
    }

    public static function fromRun(DiagnosticRun $run, ?Model $subject = null): self
    {
        return new self(
            runId: $run->id,
            suite: $run->suite,
            status: $run->status,
            outcome: $run->outcome,
            counts: $run->counts(),
            completed: $run->completedCount(),
            total: $run->total_checks,
            startedAt: $run->started_at?->toIso8601String(),
            finishedAt: $run->finished_at?->toIso8601String(),
            fresh: $run->isFreshFor($subject),
            stalenessReason: $run->stalenessReason($subject),
        );
    }

    public function isRunning(): bool
    {
        return ! $this->status->isTerminal();
    }

    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'suite' => $this->suite,
            'status' => $this->status->value,
            'outcome' => $this->outcome?->value,
            'counts' => $this->counts,
            'completed' => $this->completed,
            'total' => $this->total,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'fresh' => $this->fresh,
            'staleness_reason' => $this->stalenessReason,
        ];
    }
}
