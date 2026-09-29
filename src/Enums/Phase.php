<?php

namespace Mralston\Diagnostics\Enums;

/**
 * Where a queued run is in its life. The parallel batches run first, then a
 * single serial batch for checks that must not overlap anything, then the
 * finaliser. Each transition is claimed with one conditional UPDATE so that
 * exactly one worker performs it, whatever order the batches finish in.
 */
enum Phase: string
{
    case Parallel = 'parallel';
    case Serial = 'serial';
    case Finalising = 'finalising';
    case Done = 'done';
}
