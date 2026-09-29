<?php

namespace Mralston\Diagnostics\Enums;

enum Executor: string
{
    case Sync = 'sync';
    case Queued = 'queued';
}
