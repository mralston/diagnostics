<?php

use Illuminate\Support\Facades\Event;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Events\CheckCompleted;
use Mralston\Diagnostics\Events\CheckStarted;
use Mralston\Diagnostics\Events\RunCompleted;
use Mralston\Diagnostics\Events\RunStarted;
use Mralston\Diagnostics\Facades\Diagnostics;

it('runs every check in process and records a passing run', function () {
    $run = Diagnostics::run($this->widget());

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->outcome)->toBe(RunOutcome::Passed)
        ->and($run->total_checks)->toBe(7)
        ->and($run->passed_count)->toBe(7)
        ->and($run->results)->toHaveCount(7)
        ->and($run->results->pluck('status')->unique()->all())->toEqual([\Mralston\Diagnostics\Enums\ResultStatus::Completed])
        ->and($run->duration_ms)->toBeInt()
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->subject_updated_at)->not->toBeNull();
});

it('derives the run outcome with failure beating error beating warning', function () {
    $broken = Diagnostics::run($this->widget(['broken' => true, 'explode' => true, 'warn' => true]));
    expect($broken->outcome)->toBe(RunOutcome::Failed)
        ->and($broken->failed_count)->toBe(1)
        ->and($broken->errored_count)->toBe(1)
        ->and($broken->warning_count)->toBe(1);

    $errored = Diagnostics::run($this->widget(['explode' => true, 'warn' => true]));
    expect($errored->outcome)->toBe(RunOutcome::Errored);

    $warned = Diagnostics::run($this->widget(['warn' => true]));
    expect($warned->outcome)->toBe(RunOutcome::PassedWithWarnings);
});

it('records findings, downgrades advisory failures, skips and captures exceptions', function () {
    $run = Diagnostics::run($this->widget(['broken' => true, 'advisory_broken' => true, 'colour' => null, 'explode' => true]));
    $byTitle = $run->results->keyBy('title');

    expect($byTitle['Fails when broken']->outcome)->toBe(Outcome::Failed)
        ->and($byTitle['Fails when broken']->summary)->toBe('The widget is broken')
        ->and($byTitle['Fails when broken']->findings)->toHaveCount(2)
        ->and($byTitle['Fails when broken']->findings[0])->toBe(['message' => 'Broken flag is set', 'data' => ['field' => 'broken']])
        ->and($byTitle['Advisory failure']->outcome)->toBe(Outcome::Warning)
        ->and($byTitle['Advisory failure']->downgraded)->toBeTrue()
        ->and($byTitle['Skips without colour']->outcome)->toBe(Outcome::Skipped)
        ->and($byTitle['Explodes on demand']->outcome)->toBe(Outcome::Errored)
        ->and($byTitle['Explodes on demand']->error)->toBe('RuntimeException: Warp core breach')
        ->and($run->warning_count)->toBe(1)
        ->and($run->skipped_count)->toBe(1);
});

it('raises the four events in order', function () {
    Event::fake([RunStarted::class, CheckStarted::class, CheckCompleted::class, RunCompleted::class]);

    Diagnostics::run($this->widget());

    Event::assertDispatched(RunStarted::class, 1);
    Event::assertDispatched(CheckStarted::class, 7);
    Event::assertDispatched(CheckCompleted::class, 7);
    Event::assertDispatched(RunCompleted::class, fn (RunCompleted $e) => $e->run->outcome === RunOutcome::Passed);
});

it('keeps the broadcast payload for a check compact', function () {
    $run = Diagnostics::run($this->widget(['broken' => true]));
    $result = $run->results->firstWhere('title', 'Fails when broken');

    $payload = (new CheckCompleted($run, $result))->broadcastWith();

    expect($payload['outcome'])->toBe('failed')
        ->and($payload['findings'])->toBe([['message' => 'Broken flag is set'], ['message' => 'Second finding']])
        ->and($payload['counts']['failed'])->toBe(1)
        ->and(strlen(json_encode($payload)))->toBeLessThan(2048);
});

it('reports status and the latest run through the manager', function () {
    $widget = $this->widget(['warn' => true]);

    expect(Diagnostics::status($widget))->toBeNull();

    $run = Diagnostics::run($widget);
    $status = Diagnostics::status($widget);

    expect(Diagnostics::latestRun($widget)->id)->toBe($run->id)
        ->and($status->outcome)->toBe(RunOutcome::PassedWithWarnings)
        ->and($status->completed)->toBe(7)
        ->and($status->total)->toBe(7)
        ->and($status->fresh)->toBeTrue()
        ->and($status->toArray()['counts']['warning'])->toBe(1);
});
