<?php

namespace Mralston\Diagnostics\Enums;

enum Outcome: string
{
    case Passed = 'passed';
    case Warning = 'warning';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Errored = 'errored';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Warning => 'Warning',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
            self::Errored => 'Errored',
        };
    }

    /** The column on diagnostic_runs that counts this outcome. */
    public function countColumn(): string
    {
        return $this->value.'_count';
    }
}
