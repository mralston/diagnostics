<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Basics;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class WarnsWhenAsked extends Check
{
    protected string $title = 'Warns when asked';

    protected int $order = 30;

    public function run(Widget $widget): Result
    {
        return $widget->warn ? $this->warn('Something to look at') : $this->pass();
    }
}
