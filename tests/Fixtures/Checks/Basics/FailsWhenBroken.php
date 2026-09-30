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

    protected string $fixLabel = 'Mend it';

    public function run(Widget $widget): Result
    {
        if ($widget->broken) {
            $result = $this->fail('The widget is broken')
                ->finding('Broken flag is set', ['field' => 'broken'])
                ->finding('Second finding');

            // A black widget is beyond repair, so no fix is offered for it.
            return $widget->colour === 'black' ? $result->withoutFix() : $result;
        }

        return $this->pass();
    }

    /** Behaviour is chosen by the widget's name, so each test can exercise one path. */
    public function fix(Widget $widget, array $answers): ?string
    {
        return match ($widget->name) {
            'Stubborn' => $this->cannotFix('This widget will not be mended'),
            'Volatile' => (function () use ($widget) {
                $widget->update(['broken' => false, 'colour' => 'scorched']);
                throw new \RuntimeException('Boom');
            })(),
            'Relapsing' => null,
            default => tap('Mended the widget', fn () => $widget->update(['broken' => false])),
        };
    }
}
