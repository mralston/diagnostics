<?php

namespace Mralston\Diagnostics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;

/** @mixin DiagnosticRun */
class RunResource extends JsonResource
{
    public function __construct(DiagnosticRun $resource, private readonly bool $withResults = true)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'suite' => $this->suite,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'status' => $this->status->value,
            'outcome' => $this->outcome?->value,
            'executor' => $this->executor->value,
            'triggered_by' => $this->triggered_by->value,
            'counts' => $this->counts(),
            'completed' => $this->completedCount(),
            'total_checks' => $this->total_checks,
            'waiting_for_worker' => $this->waitingForWorker(),
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
        ];

        // Built by hand rather than with when(): these resources are turned into arrays
        // directly, and when()'s placeholder only disappears inside a JSON response.
        if ($this->withResults) {
            $data['results'] = $this->results
                ->map(fn (DiagnosticResult $result) => (new ResultResource($result))->toArray($request))
                ->values()
                ->all();
        }

        return $data;
    }
}
