<?php

use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Tests\Fixtures\Checks\Basics\AlwaysPasses;
use Mralston\Diagnostics\Tests\Fixtures\Checks\Extra\Explodes;

it('discovers checks, derives categories from directories and sorts them', function () {
    $checks = Diagnostics::get('widgets')->checks();

    expect($checks)->toHaveCount(7)
        ->and($checks->pluck('class'))->not->toContain('Mralston\Diagnostics\Tests\Fixtures\Checks\Extra\NotACheck')
        ->and($checks->pluck('category')->unique()->values()->all())->toBe(['Basics', 'Extra'])
        ->and($checks->first()->class)->toBe(AlwaysPasses::class)
        ->and($checks->first()->position)->toBe(1)
        ->and($checks->take(3)->pluck('title')->all())->toBe(['Always passes', 'Fails when broken', 'Warns when asked'])
        ->and($checks->slice(3)->pluck('title')->all())->toBe(['Advisory failure', 'Explodes on demand', 'Runs alone', 'Skips without colour']);
});

it('leaves out checks disabled in config', function () {
    config(['diagnostics.suites.widgets.disabled' => [Explodes::class]]);
    Diagnostics::get('widgets')->forgetChecks();

    expect(Diagnostics::get('widgets')->checks()->pluck('class'))->not->toContain(Explodes::class);
});

it('takes the label from config over the registration, and headlines the key otherwise', function () {
    expect(Diagnostics::get('widgets')->getLabel())->toBe('Widget Doctor');

    config(['diagnostics.suites.widgets.label' => 'Widget Clinic']);
    expect(Diagnostics::get('widgets')->getLabel())->toBe('Widget Clinic');

    expect(Diagnostics::suite('thing-checker')->getLabel())->toBe('Thing Checker');
});

it('finds the suite for a subject class', function () {
    expect(Diagnostics::suiteFor($this->widget())->getKey())->toBe('widgets');
});
