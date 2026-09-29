<?php

namespace Mralston\Diagnostics\Exceptions;

use Mralston\Diagnostics\Models\DiagnosticRun;
use RuntimeException;

/**
 * Thrown by the chain step when a run's outcome is not acceptable, which
 * stops the rest of the chain. Carries the run so a catch() can report it.
 */
class DiagnosisFailed extends RuntimeException
{
    public function __construct(public readonly DiagnosticRun $run)
    {
        $titles = $run->results()
            ->whereIn('outcome', ['failed', 'errored'])
            ->orderBy('position')
            ->pluck('title')
            ->take(5)
            ->implode(', ');

        parent::__construct(sprintf(
            'Diagnostics run %d (%s) on %s #%s finished %s%s.',
            $run->id,
            $run->suite,
            class_basename($run->subject_type),
            $run->subject_id,
            $run->outcome?->label() ?? 'incomplete',
            $titles !== '' ? ': '.$titles : '',
        ));
    }
}
