<?php

namespace Mralston\Diagnostics\Runs;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Executors\QueuedExecutor;
use Mralston\Diagnostics\Executors\SyncExecutor;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Suite;
use Throwable;

/**
 * Starts runs. Queued starts coalesce onto a run already in flight for the
 * same subject, so two people clicking Run at once watch the same one.
 */
class Dispatcher
{
    public function __construct(private readonly RunFactory $factory)
    {
    }

    public function start(
        Suite $suite,
        Model $subject,
        Executor $executor,
        Trigger $trigger = Trigger::Code,
        ?string $triggeredById = null,
        ?bool $coalesce = null,
    ): StartedRun {
        $coalesce ??= (bool) config('diagnostics.coalesce_in_flight', true);
        $lock = $this->acquireLock($suite, $subject);

        try {
            if ($coalesce && $executor === Executor::Queued) {
                $inFlight = DiagnosticRun::forSubject($subject)
                    ->suite($suite->getKey())
                    ->inFlight()
                    ->latest('id')
                    ->first();

                if ($inFlight !== null) {
                    return new StartedRun($inFlight->load('results'), true);
                }
            }

            $run = $this->factory->create($suite, $subject, $executor, $trigger, $triggeredById);
        } finally {
            $lock?->release();
        }

        $this->executor($executor)->execute($run);

        return new StartedRun($run->refresh()->load('results'), false);
    }

    public function executor(Executor $executor): SyncExecutor|QueuedExecutor
    {
        return match ($executor) {
            Executor::Sync => app(SyncExecutor::class),
            Executor::Queued => app(QueuedExecutor::class),
        };
    }

    /**
     * Closes the check-then-create race between two simultaneous starts. A
     * cache store without lock support only costs the coalescing guarantee.
     */
    private function acquireLock(Suite $suite, Model $subject): ?Lock
    {
        $key = sprintf('diagnostics:start:%s:%s:%s', $suite->getKey(), $subject->getMorphClass(), $subject->getKey());

        try {
            $lock = Cache::lock($key, 10);

            return $lock->block(5) ? $lock : null;
        } catch (Throwable $e) {
            Log::debug('Diagnostics start lock unavailable; continuing without it.', ['message' => $e->getMessage()]);

            return null;
        }
    }
}
