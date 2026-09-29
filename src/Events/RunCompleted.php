<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Mralston\Diagnostics\Models\DiagnosticRun;

/**
 * Fired once per run, broadcast to the browser and available to ordinary
 * listeners in the host, which receive the run model itself.
 */
class RunCompleted implements ShouldBroadcastNow
{
    use BroadcastsOnRunChannel, Dispatchable, InteractsWithSockets;

    public function __construct(public readonly DiagnosticRun $run)
    {
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'suite' => $this->run->suite,
            'status' => $this->run->status->value,
            'outcome' => $this->run->outcome?->value,
            'counts' => $this->run->counts(),
            'total_checks' => $this->run->total_checks,
            'duration_ms' => $this->run->duration_ms,
            'finished_at' => $this->run->finished_at?->toIso8601String(),
        ];
    }
}
