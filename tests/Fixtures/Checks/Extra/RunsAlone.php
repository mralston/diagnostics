<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Extra;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class RunsAlone extends Check
{
    protected string $title = 'Runs alone';

    protected bool $parallelSafe = false;

    public static array $seenPositions = [];

    public function run(Widget $widget): Result
    {
        return $this->pass();
    }
}
