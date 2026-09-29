<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Extra;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class AdvisoryFailure extends Check
{
    protected string $title = 'Advisory failure';

    protected bool $canFail = false;

    public function run(Widget $widget): Result
    {
        return $widget->advisory_broken ? $this->fail('Advisory problem') : $this->pass();
    }
}
