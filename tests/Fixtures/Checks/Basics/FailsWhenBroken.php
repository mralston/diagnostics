<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Basics;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class FailsWhenBroken extends Check
{
    protected string $title = 'Fails when broken';

    protected ?string $description = 'A widget flagged as broken fails this check.';

    protected int $order = 20;

    public function run(Widget $widget): Result
    {
        if ($widget->broken) {
            return $this->fail('The widget is broken')
                ->finding('Broken flag is set', ['field' => 'broken'])
                ->finding('Second finding');
        }

        return $this->pass();
    }
}
