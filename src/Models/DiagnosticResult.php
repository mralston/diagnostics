<?php

namespace Mralston\Diagnostics\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Finding;

/**
 * @property int $id
 * @property int $run_id
 * @property string $check_class
 * @property string $title
 * @property string $category
 * @property int $position
 * @property int|null $batch
 * @property bool $parallel_safe
 * @property bool $can_fail
 * @property ResultStatus $status
 * @property Outcome|null $outcome
 * @property bool $downgraded
 * @property string|null $summary
 * @property array|null $findings
 * @property string|null $error
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property int|null $duration_ms
 * @property bool $fixable
 * @property string|null $fix_label
 * @property CarbonInterface|null $fixed_at
 */
class DiagnosticResult extends Model
{
    protected $table = 'diagnostic_results';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'batch' => 'integer',
            'parallel_safe' => 'boolean',
            'can_fail' => 'boolean',
            'status' => ResultStatus::class,
            'outcome' => Outcome::class,
            'downgraded' => 'boolean',
            'findings' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_ms' => 'integer',
            'fixable' => 'boolean',
            'fixed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class, 'run_id');
    }

    public function fixes(): HasMany
    {
        return $this->hasMany(DiagnosticFix::class, 'result_id');
    }

    /** @return Finding[] */
    public function findingObjects(): array
    {
        return array_map(fn (array $f) => Finding::fromArray($f), $this->findings ?? []);
    }

    public function isFinished(): bool
    {
        return $this->status === ResultStatus::Completed;
    }
}
