<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Extra;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;
use RuntimeException;

class Explodes extends Check
{
    protected string $title = 'Explodes on demand';

    public function run(Widget $widget): Result
    {
        if ($widget->explode) {
            throw new RuntimeException('Warp core breach');
        }

        return $this->pass();
    }
}
