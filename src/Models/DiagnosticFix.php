<?php

namespace Mralston\Diagnostics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mralston\Diagnostics\Enums\FixStatus;
use Mralston\Diagnostics\Enums\Outcome;

/**
 * A record of one attempt to fix a result: who asked, what they answered,
 * and what the check said before and after.
 *
 * @property int $id
 * @property int $run_id
 * @property int $result_id
 * @property string $check_class
 * @property FixStatus $status
 * @property Outcome|null $outcome_before
 * @property Outcome|null $outcome_after
 * @property array|null $answers
 * @property string|null $message
 * @property string|null $error
 * @property string|null $fixed_by
 * @property int|null $duration_ms
 */
class DiagnosticFix extends Model
{
    protected $table = 'diagnostic_fixes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => FixStatus::class,
            'outcome_before' => Outcome::class,
            'outcome_after' => Outcome::class,
            'answers' => 'array',
            'duration_ms' => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(DiagnosticRun::class, 'run_id');
    }

    public function result(): BelongsTo
    {
        return $this->belongsTo(DiagnosticResult::class, 'result_id');
    }

    /** Did the fix run and leave the check passing (or skipped)? */
    public function resolved(): bool
    {
        return $this->status === FixStatus::Succeeded
            && in_array($this->outcome_after, [Outcome::Passed, Outcome::Skipped], true);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'result_id' => $this->result_id,
            'status' => $this->status->value,
            'resolved' => $this->resolved(),
            'outcome_before' => $this->outcome_before?->value,
            'outcome_after' => $this->outcome_after?->value,
            'message' => $this->message,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
