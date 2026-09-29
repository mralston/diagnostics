<?php

namespace Mralston\Diagnostics\Enums;

enum RunOutcome: string
{
    case Passed = 'passed';
    case PassedWithWarnings = 'passed_with_warnings';
    case Failed = 'failed';
    case Errored = 'errored';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::PassedWithWarnings => 'Passed with warnings',
            self::Failed => 'Failed',
            self::Errored => 'Errored',
        };
    }

    /**
     * Any failure fails the run; otherwise an error does, because a run that
     * could not check something must not read as a pass; otherwise a warning
     * qualifies the pass.
     */
    public static function fromCounts(int $failed, int $errored, int $warnings): self
    {
        return match (true) {
            $failed > 0 => self::Failed,
            $errored > 0 => self::Errored,
            $warnings > 0 => self::PassedWithWarnings,
            default => self::Passed,
        };
    }
}
