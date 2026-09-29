<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Basics;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class AlwaysPasses extends Check
{
    protected string $title = 'Always passes';

    protected int $order = 10;

    public function run(Widget $widget): Result
    {
        return $this->pass('Fine');
    }
}
