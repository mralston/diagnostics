<?php

use Illuminate\Support\Facades\Blade;
use Mralston\Diagnostics\Facades\Diagnostics;

it('returns the latest run for each subject in one query', function () {
    $a = $this->widget();
    $b = $this->widget(['broken' => true]);
    $never = $this->widget();

    Diagnostics::run($a);
    $latestA = Diagnostics::run($a);
    $latestB = Diagnostics::run($b);

    DB::enableQueryLog();
    $runs = Diagnostics::latestRuns([$a, $b, $never]);
    $queries = count(DB::getQueryLog());

    expect($runs->keys()->map(fn ($k) => (string) $k)->sort()->values()->all())->toBe([(string) $a->id, (string) $b->id])
        ->and($runs[(string) $a->id]->id)->toBe($latestA->id)
        ->and($runs[(string) $b->id]->id)->toBe($latestB->id)
        ->and($queries)->toBe(1)
        ->and(Diagnostics::latestRuns([]))->toBeEmpty();
});

it('renders an outcome symbol for a run and nothing without one', function () {
    $passed = Diagnostics::run($w = $this->widget());
    $failed = Diagnostics::run($this->widget(['broken' => true]));

    $html = Blade::render('<x-diagnostics::outcome :run="$run" :subject="$subject" />', ['run' => $passed, 'subject' => $w]);
    expect($html)->toContain('dx-outcome--passed')->toContain('Widget Doctor passed')->not->toContain('dx-outcome--stale');

    $outline = Blade::render('<x-diagnostics::outcome :run="$run" variant="outline" />', ['run' => $failed]);
    expect($outline)->toContain('dx-outcome--failed')->toContain('stroke="#c4342d"');

    expect(trim(Blade::render('<x-diagnostics::outcome :run="$run" />', ['run' => null])))->toBe('');
});

it('marks a run whose subject has changed since', function () {
    $widget = $this->widget();
    $run = Diagnostics::run($widget);

    Illuminate\Support\Carbon::setTestNow(now()->addMinute());
    $widget->forceFill(['name' => 'Defiant'])->save();

    $html = Blade::render('<x-diagnostics::outcome :run="$run" :subject="$subject" />', ['run' => $run, 'subject' => $widget->fresh()]);
    expect($html)->toContain('dx-outcome--stale')->toContain('The record has changed since.');
    Illuminate\Support\Carbon::setTestNow();
});

it('merges a host style with its own', function () {
    $run = Diagnostics::run($this->widget());

    $html = Blade::render('<x-diagnostics::outcome :run="$run" style="margin-left: 4px;" />', ['run' => $run]);

    expect(substr_count($html, 'style='))->toBe(1)->and($html)->toContain('margin-left: 4px;')->toContain('display: inline-flex');
});

it('accepts a paginator of subjects', function () {
    $a = $this->widget();
    Diagnostics::run($a);
    $this->widget();

    $page = Mralston\Diagnostics\Tests\Fixtures\Widget::query()->paginate(10);

    expect(Diagnostics::latestRuns($page)->keys()->map(fn ($k) => (string) $k)->all())->toBe([(string) $a->id]);
});
