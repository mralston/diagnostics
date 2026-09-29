<?php

namespace Mralston\Diagnostics\Enums;

enum Trigger: string
{
    case Http = 'http';
    case Artisan = 'artisan';
    case Chain = 'chain';
    case Job = 'job';
    case Code = 'code';
}
