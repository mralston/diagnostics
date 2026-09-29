<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Mralston\Diagnostics\Models\DiagnosticRun;

class RunStarted implements ShouldBroadcastNow
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
            'subject_type' => $this->run->subject_type,
            'subject_id' => $this->run->subject_id,
            'status' => $this->run->status->value,
            'total_checks' => $this->run->total_checks,
            'created_at' => $this->run->created_at?->toIso8601String(),
        ];
    }
}
