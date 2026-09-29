<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Extra;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class SkipsWithoutColour extends Check
{
    protected string $title = 'Skips without colour';

    public function run(Widget $widget): Result
    {
        return $widget->colour === null ? $this->skip('no colour') : $this->pass($widget->colour);
    }
}
