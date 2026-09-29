<?php

namespace Mralston\Diagnostics\Enums;

enum ResultStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
}
