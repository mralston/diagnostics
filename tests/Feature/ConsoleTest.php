<?php

use Illuminate\Support\Carbon;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\RunStatus;
use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\RunFactory;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;

it('runs in process, prints results grouped by category and exits by outcome', function () {
    $broken = $this->widget(['broken' => true]);

    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $broken->id])
        ->expectsOutputToContain('Widget Doctor: Widget #'.$broken->id)
        ->expectsOutputToContain('Basics')
        ->expectsOutputToContain('Fails when broken')
        ->expectsOutputToContain('The widget is broken')
        ->expectsOutputToContain('Outcome: FAILED')
        ->assertExitCode(1);

    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $this->widget()->id])->assertExitCode(0);
    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $this->widget(['warn' => true])->id, '--fail-on-warnings' => true])->assertExitCode(1);
    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $this->widget(['explode' => true])->id])->assertExitCode(2);
    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => 999])->assertExitCode(2);
    $this->artisan('diagnostics:run', ['suite' => 'nope', 'subject' => 1])->assertExitCode(2);
});

it('prints json when asked', function () {
    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $this->widget()->id, '--json' => true])
        ->expectsOutputToContain('"outcome": "passed"')
        ->assertExitCode(0);
});

it('tails a queued run', function () {
    $this->artisan('diagnostics:run', ['suite' => 'widgets', 'subject' => $this->widget()->id, '--queue' => true])
        ->expectsOutputToContain('[Basics] Always passes')
        ->expectsOutputToContain('Outcome: PASSED')
        ->assertExitCode(0);
});

it('lists suites and checks', function () {
    $this->artisan('diagnostics:list')->expectsOutputToContain('widgets')->assertExitCode(0);
    $this->artisan('diagnostics:list', ['suite' => 'widgets'])->expectsOutputToContain('Runs alone')->assertExitCode(0);
    $this->artisan('diagnostics:list', ['suite' => 'widgets'])->expectsOutputToContain('| serial |')->assertExitCode(0);
});

it('reaps runs with no activity', function () {
    $run = app(RunFactory::class)->create(Diagnostics::get('widgets'), $this->widget(), Executor::Queued, Trigger::Http);
    $run->forceFill(['last_activity_at' => now()->subMinutes(30)])->save();

    $this->artisan('diagnostics:reap')->expectsOutputToContain('1 run abandoned')->assertExitCode(0);

    $run->refresh();
    expect($run->status)->toBe(RunStatus::Abandoned)
        ->and($run->errored_count)->toBe(7)
        ->and($run->results->pluck('outcome')->unique()->all())->toEqual([Outcome::Errored]);
});

it('prunes old runs', function () {
    Diagnostics::run($this->widget());
    DiagnosticRun::query()->update(['created_at' => Carbon::now()->subDays(40)]);
    Diagnostics::run($this->widget());

    $this->artisan('diagnostics:prune')->assertExitCode(0);

    expect(DiagnosticRun::count())->toBe(1);
});
