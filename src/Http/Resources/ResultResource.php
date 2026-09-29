<?php

namespace Mralston\Diagnostics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Mralston\Diagnostics\Models\DiagnosticResult;

/** @mixin DiagnosticResult */
class ResultResource extends JsonResource
{
    public function __construct(DiagnosticResult $resource, private readonly bool $full = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $findings = $this->findings ?? [];

        return [
            'id' => $this->id,
            'position' => $this->position,
            'check_class' => $this->check_class,
            'title' => $this->title,
            'category' => $this->category,
            'batch' => $this->batch,
            'parallel_safe' => $this->parallel_safe,
            'can_fail' => $this->can_fail,
            'status' => $this->status->value,
            'outcome' => $this->outcome?->value,
            'downgraded' => $this->downgraded,
            'summary' => $this->summary,
            'findings_count' => count($findings),
            'findings' => $this->full
                ? $findings
                : array_map(fn (array $f) => ['message' => $f['message'] ?? '', 'data' => $f['data'] ?? []], $findings),
            'error' => $this->when($this->full, $this->error),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'duration_ms' => $this->duration_ms,
        ];
    }
}
