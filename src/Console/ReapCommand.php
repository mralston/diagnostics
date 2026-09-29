<?php

namespace Mralston\Diagnostics\Console;

use Illuminate\Console\Command;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Finaliser;

/**
 * Closes runs that stopped making progress, which happens when a worker is
 * lost in a way that never reaches the job's failed() hook. Whatever did
 * finish is kept; the rest is recorded as errored.
 */
class ReapCommand extends Command
{
    protected $signature = 'diagnostics:reap {--minutes= : Override the configured inactivity threshold}';

    protected $description = 'Abandon diagnostics runs that have had no activity for too long';

    public function handle(Finaliser $finaliser): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('diagnostics.stuck_after_minutes', 15));
        $threshold = now()->subMinutes($minutes);

        $stuck = DiagnosticRun::query()
            ->whereIn('status', [RunStatus::Pending->value, RunStatus::Running->value])
            ->where(function ($query) use ($threshold) {
                $query->where('last_activity_at', '<', $threshold)
                    ->orWhere(fn ($q) => $q->whereNull('last_activity_at')->where('created_at', '<', $threshold));
            })
            ->get();

        foreach ($stuck as $run) {
            $finaliser->finalise($run, RunStatus::Abandoned, sprintf('Abandoned after %d minutes without activity.', $minutes));
        }

        $this->components->info(sprintf('%d %s abandoned.', $stuck->count(), $stuck->count() === 1 ? 'run' : 'runs'));

        return 0;
    }
}
