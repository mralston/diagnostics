<?php

namespace Mralston\Diagnostics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Suite;

/**
 * @property int $id
 * @property string $suite
 * @property string $subject_type
 * @property string $subject_id
 * @property RunStatus $status
 * @property Phase $phase
 * @property RunOutcome|null $outcome
 * @property Executor $executor
 * @property Trigger $triggered_by
 * @property string|null $triggered_by_id
 * @property int $total_checks
 * @property int $passed_count
 * @property int $warning_count
 * @property int $failed_count
 * @property int $skipped_count
 * @property int $errored_count
 * @property int $pending_batches
 * @property CarbonInterface|null $subject_updated_at
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_ms
 * @property CarbonInterface|null $last_activity_at
 * @property CarbonInterface $created_at
 */
class DiagnosticRun extends Model
{
    use MassPrunable;

    protected $table = 'diagnostic_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'phase' => Phase::class,
            'outcome' => RunOutcome::class,
            'executor' => Executor::class,
            'triggered_by' => Trigger::class,
            'total_checks' => 'integer',
            'passed_count' => 'integer',
            'warning_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'errored_count' => 'integer',
            'pending_batches' => 'integer',
            'subject_updated_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'fixed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'duration_ms' => 'integer',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(DiagnosticResult::class, 'run_id')->orderBy('position');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function suiteDefinition(): Suite
    {
        return app(DiagnosticsManager::class)->get($this->suite);
    }

    // -- Scopes -------------------------------------------------------------------------------

    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', (string) $subject->getKey());
    }

    public function scopeSuite(Builder $query, string $suite): Builder
    {
        return $query->where('suite', $suite);
    }

    /** Runs still going, ignoring anything silent for longer than the stuck threshold. */
    public function scopeInFlight(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->where('last_activity_at', '>', now()->subMinutes((int) config('diagnostics.stuck_after_minutes', 15)));
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', RunStatus::Completed->value);
    }

    // -- State --------------------------------------------------------------------------------

    /** @return array<string, int> */
    public function counts(): array
    {
        $counts = [];

        foreach (Outcome::cases() as $outcome) {
            $counts[$outcome->value] = (int) $this->{$outcome->countColumn()};
        }

        return $counts;
    }

    public function completedCount(): int
    {
        return array_sum($this->counts());
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isComplete(): bool
    {
        return $this->status === RunStatus::Completed;
    }

    /** True while the run has not started and has waited longer than the configured grace. */
    public function waitingForWorker(): bool
    {
        return $this->status === RunStatus::Pending
            && $this->started_at === null
            && $this->created_at !== null
            && $this->created_at->diffInSeconds(now()) >= (int) config('diagnostics.waiting_for_worker_after', 10);
    }

    /**
     * A completed run represents the subject only until the subject changes or
     * the run ages past the configured TTL.
     */
    public function isFreshFor(?Model $subject = null): bool
    {
        return $this->stalenessReason($subject) === null;
    }

    public function stalenessReason(?Model $subject = null): ?string
    {
        if (! $this->isComplete()) {
            return 'The run has not completed.';
        }

        if ($this->fixed_at !== null) {
            return 'A fix has been applied since the last run.';
        }

        $ttl = (int) config('diagnostics.freshness.ttl_minutes', 1440);

        if ($ttl > 0 && $this->finished_at !== null && $this->finished_at->lt(now()->subMinutes($ttl))) {
            return 'The last run is too old.';
        }

        $subject ??= $this->subject;

        if ($subject !== null && $this->subject_updated_at !== null && $subject->usesTimestamps()) {
            $updatedAt = $subject->{$subject->getUpdatedAtColumn()};

            if ($updatedAt !== null && $updatedAt->gt($this->subject_updated_at)) {
                return 'The record has changed since the last run.';
            }
        }

        return null;
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays((int) config('diagnostics.retention_days', 30)));
    }
}
