<?php

use Illuminate\Support\Facades\Queue;
use Mralston\Diagnostics\Enums\Phase;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Jobs\RunCheckBatch;
use Mralston\Diagnostics\Runs\Dispatcher;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;

it('runs a queued run to completion under the sync driver with the same results as an in-process run', function () {
    $widget = $this->widget(['broken' => true, 'warn' => true, 'colour' => null]);

    $sync = Diagnostics::run($widget);
    $queued = Diagnostics::dispatch($widget);

    expect($queued->status)->toBe(RunStatus::Completed)
        ->and($queued->phase)->toBe(Phase::Done)
        ->and($queued->pending_batches)->toBe(0)
        ->and($queued->outcome)->toBe(RunOutcome::Failed)
        ->and($queued->results->pluck('outcome', 'title')->all())->toEqual($sync->results->pluck('outcome', 'title')->all())
        ->and($queued->results->firstWhere('title', 'Runs alone')->batch)->toBe(0)
        ->and($queued->results->where('parallel_safe', true)->pluck('batch')->unique()->sort()->values()->all())->toBe([1, 2, 3]);
});

it('fans out into the configured number of batches on the configured queue', function () {
    Queue::fake();
    config(['diagnostics.suites.widgets.queue' => 'diag', 'diagnostics.suites.widgets.parallel_batches' => 2]);

    $run = Diagnostics::dispatch($this->widget());

    expect($run->status)->toBe(RunStatus::Pending)
        ->and($run->pending_batches)->toBe(2)
        ->and($run->phase)->toBe(Phase::Parallel);

    Queue::assertPushed(RunCheckBatch::class, 2);
    Queue::assertPushedOn('diag', RunCheckBatch::class);
});

it('finalises only when the last parallel batch finishes, then runs the serial batch', function () {
    Queue::fake();
    $run = Diagnostics::dispatch($this->widget());
    $jobs = Queue::pushed(RunCheckBatch::class)->all();
    expect($jobs)->toHaveCount(3);

    // Finish the batches in reverse order to prove the claim does not depend on ordering.
    $jobs[2]->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));
    expect($run->refresh()->status)->toBe(RunStatus::Running)->and($run->pending_batches)->toBe(2);

    $jobs[1]->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));
    expect($run->refresh()->status)->toBe(RunStatus::Running)->and($run->phase)->toBe(Phase::Parallel);

    $jobs[0]->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));

    // The serial batch was dispatched (into the fake) and the run is waiting on it.
    expect($run->refresh()->phase)->toBe(Phase::Serial)->and($run->pending_batches)->toBe(1);
    Queue::assertPushed(RunCheckBatch::class, 4);

    $serial = Queue::pushed(RunCheckBatch::class)->last();
    $serial->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));

    expect($run->refresh()->status)->toBe(RunStatus::Completed)
        ->and($run->outcome)->toBe(RunOutcome::Passed)
        ->and($run->passed_count)->toBe(7);
});

it('records a lost worker as errored results and still closes the run', function () {
    Queue::fake();
    $run = Diagnostics::dispatch($this->widget());
    $jobs = Queue::pushed(RunCheckBatch::class)->all();

    $jobs[0]->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));
    $jobs[1]->failed(new RuntimeException('killed'));
    $jobs[2]->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));
    Queue::pushed(RunCheckBatch::class)->last()->handle(app(\Mralston\Diagnostics\Runs\Runner::class), app(\Mralston\Diagnostics\Runs\PhaseAdvancer::class));

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Completed)
        ->and($run->outcome)->toBe(RunOutcome::Errored)
        ->and($run->errored_count)->toBe(count($jobs[1]->resultIds))
        ->and($run->results->whereIn('id', $jobs[1]->resultIds)->pluck('error')->first())->toContain('killed');
});

it('coalesces a second queued start onto the run in flight', function () {
    Queue::fake();
    $widget = $this->widget();
    $suite = Diagnostics::get('widgets');

    $first = app(Dispatcher::class)->start($suite, $widget, Executor::Queued, Trigger::Http);
    $second = app(Dispatcher::class)->start($suite, $widget, Executor::Queued, Trigger::Http);

    expect($first->coalesced)->toBeFalse()
        ->and($second->coalesced)->toBeTrue()
        ->and($second->run->id)->toBe($first->run->id);

    Queue::assertPushed(RunCheckBatch::class, 3);
});

it('ignores a stuck run when deciding whether to coalesce', function () {
    Queue::fake();
    $widget = $this->widget();

    $first = Diagnostics::dispatch($widget);
    $first->forceFill(['last_activity_at' => now()->subHours(2)])->save();

    $second = Diagnostics::dispatch($widget);

    expect($second->id)->not->toBe($first->id);
});
