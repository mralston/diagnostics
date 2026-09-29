<?php

namespace Mralston\Diagnostics\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Str;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;

class CheckCompleted implements ShouldBroadcastNow
{
    use BroadcastsOnRunChannel, Dispatchable, InteractsWithSockets;

    public function __construct(
        public readonly DiagnosticRun $run,
        public readonly DiagnosticResult $result,
    ) {
    }

    /**
     * Kept small enough for Pusher: a handful of trimmed finding messages and
     * the running counts. The full result is one API call away.
     */
    public function broadcastWith(): array
    {
        $maxFindings = (int) config('diagnostics.broadcast.max_findings', 5);
        $maxLength = (int) config('diagnostics.broadcast.max_message_length', 200);
        $findings = $this->result->findings ?? [];

        $payload = [
            'run_id' => $this->run->id,
            'result_id' => $this->result->id,
            'position' => $this->result->position,
            'status' => $this->result->status->value,
            'outcome' => $this->result->outcome?->value,
            'downgraded' => $this->result->downgraded,
            'summary' => Str::limit((string) $this->result->summary, 500),
            'duration_ms' => $this->result->duration_ms,
            'findings_count' => count($findings),
            'findings' => array_map(
                fn (array $f) => ['message' => Str::limit((string) ($f['message'] ?? ''), $maxLength)],
                array_slice($findings, 0, $maxFindings)
            ),
            'findings_truncated' => count($findings) > $maxFindings,
            'counts' => $this->run->counts(),
            'completed' => $this->run->completedCount(),
            'total_checks' => $this->run->total_checks,
        ];

        if (strlen((string) json_encode($payload)) > (int) config('diagnostics.broadcast.max_payload_bytes', 8192)) {
            $payload['findings'] = [];
            $payload['findings_truncated'] = true;
        }

        return $payload;
    }
}
