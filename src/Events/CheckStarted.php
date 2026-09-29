<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;

class CheckStarted implements ShouldBroadcastNow
{
    use BroadcastsOnRunChannel, Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly DiagnosticRun $run,
        public readonly DiagnosticResult $result,
    ) {
    }

    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->run->id,
            'result_id' => $this->result->id,
            'position' => $this->result->position,
            'started_at' => $this->result->started_at?->toIso8601String(),
        ];
    }
}
