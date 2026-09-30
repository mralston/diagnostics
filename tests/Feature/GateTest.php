<?php

use Illuminate\Support\Carbon;
use Mralston\Diagnostics\Facades\Diagnostics;

it('is closed before any run, open after a passing run and closed again when the subject changes', function () {
    $widget = $this->widget();

    $gate = Diagnostics::gate($widget);
    expect($gate->passed())->toBeFalse()->and($gate->reason())->toBe('Widget Doctor has not been run yet.');

    Carbon::setTestNow(now()->addMinute());
    Diagnostics::run($widget);
    expect(Diagnostics::gate($widget)->passed())->toBeTrue()
        ->and(Diagnostics::gate($widget)->reason())->toBeNull();

    Carbon::setTestNow(now()->addMinute());
    $widget->forceFill(['name' => 'Enterprise'])->save();
    $gate = Diagnostics::gate($widget->fresh());
    expect($gate->passed())->toBeFalse()->and($gate->reason())->toBe('The record has changed since the last run.');

    Carbon::setTestNow();
});

it('treats warnings as a pass by default and a failure as closed', function () {
    $warned = $this->widget(['warn' => true]);
    Diagnostics::run($warned);
    expect(Diagnostics::gate($warned)->passed())->toBeTrue();

    $broken = $this->widget(['broken' => true]);
    Diagnostics::run($broken);
    $gate = Diagnostics::gate($broken);
    expect($gate->passed())->toBeFalse()->and($gate->reason())->toBe('The last run finished with the outcome "Failed".');
});

it('goes stale after the configured ttl', function () {
    config(['diagnostics.freshness.ttl_minutes' => 60]);
    $widget = $this->widget();
    Diagnostics::run($widget);

    Carbon::setTestNow(now()->addMinutes(61));
    expect(Diagnostics::gate($widget)->passed())->toBeFalse()
        ->and(Diagnostics::gate($widget)->reason())->toBe('The last run is too old.');
    Carbon::setTestNow();
});

it('lets a suite accept errored runs', function () {
    $widget = $this->widget(['explode' => true]);
    Diagnostics::run($widget);

    expect(Diagnostics::gate($widget)->passed())->toBeFalse();

    Diagnostics::get('widgets')->acceptable(['passed', 'passed_with_warnings', 'errored']);
    expect(Diagnostics::gate($widget)->passed())->toBeTrue();

    config(['diagnostics.suites.widgets.acceptable' => ['passed']]);
    expect(Diagnostics::gate($widget)->passed())->toBeFalse();
});
