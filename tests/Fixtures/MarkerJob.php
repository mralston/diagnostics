<?php

namespace Mralston\Diagnostics\Tests\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class MarkerJob implements ShouldQueue
{
    use Queueable;

    public static int $runs = 0;

    public function handle(): void
    {
        static::$runs++;
    }
}
