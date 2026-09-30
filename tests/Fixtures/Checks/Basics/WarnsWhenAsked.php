<?php

namespace Mralston\Diagnostics\Tests\Fixtures\Checks\Basics;

use Mralston\Diagnostics\Check;
use Mralston\Diagnostics\Fixes\Question;
use Mralston\Diagnostics\Result;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

class WarnsWhenAsked extends Check
{
    protected string $title = 'Warns when asked';

    protected int $order = 30;

    protected string $fixLabel = 'Answer the warning';

    protected ?string $fixDescription = 'Rename the widget and choose a colour.';

    public function run(Widget $widget): Result
    {
        return $widget->warn ? $this->warn('Something to look at') : $this->pass();
    }

    /** Built from the widget, so the defaults are its current values. */
    public function fixQuestions(mixed $widget): array
    {
        return [
            Question::text('name', 'New name')->default($widget->name)->rules('required|max:20'),
            Question::select('colour', 'Colour', ['red' => 'Red', 'blue' => 'Blue'])->required(),
            ['name' => 'coats', 'type' => 'number', 'label' => 'Coats of paint', 'rules' => 'integer|min:1'],
            Question::checkbox('varnish', 'Varnish it'),
        ];
    }

    public function fix(Widget $widget, array $answers): string
    {
        $widget->update(['name' => $answers['name'], 'colour' => $answers['colour'], 'warn' => false]);

        return sprintf('%s, %s coats, %s', $answers['colour'], var_export($answers['coats'], true), $answers['varnish'] ? 'varnished' : 'bare');
    }
}
