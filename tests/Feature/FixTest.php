<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Mralston\Diagnostics\Enums\FixStatus;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Events\CheckFixed;
use Mralston\Diagnostics\Exceptions\FixUnavailable;
use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Fixes\Question;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Tests\Fixtures\Checks\Basics\FailsWhenBroken;
use Mralston\Diagnostics\Tests\Fixtures\Checks\Basics\WarnsWhenAsked;
use Mralston\Diagnostics\Tests\Fixtures\Widget;

function resultFor(DiagnosticRun $run, string $class): DiagnosticResult
{
    return $run->results()->where('check_class', $class)->firstOrFail();
}

it('offers a fix only on failed and warning results of checks that have one', function () {
    $run = Diagnostics::run($this->widget(['broken' => true, 'warn' => true]));

    $broken = resultFor($run, FailsWhenBroken::class);
    expect($broken->fixable)->toBeTrue()
        ->and($broken->fix_label)->toBe('Mend it')
        ->and(resultFor($run, WarnsWhenAsked::class)->fixable)->toBeTrue()
        ->and($run->results()->where('fixable', true)->count())->toBe(2);

    $clean = Diagnostics::run($this->widget());
    expect($clean->results()->where('fixable', true)->count())->toBe(0);
});

it('lets a check withhold its fix for one result', function () {
    $run = Diagnostics::run($this->widget(['broken' => true, 'colour' => 'black']));

    expect(resultFor($run, FailsWhenBroken::class)->fixable)->toBeFalse();
});

it('applies a fix, re-checks, and marks the run out of date', function () {
    Event::fake([CheckFixed::class]);
    $widget = $this->widget(['broken' => true]);
    $run = Diagnostics::run($widget);
    expect($run->outcome->value)->toBe('failed');

    $fix = Diagnostics::fix(resultFor($run, FailsWhenBroken::class), [], $this->user(7));

    expect($fix->status)->toBe(FixStatus::Succeeded)
        ->and($fix->resolved())->toBeTrue()
        ->and($fix->message)->toBe('Mended the widget')
        ->and($fix->outcome_before)->toBe(Outcome::Failed)
        ->and($fix->outcome_after)->toBe(Outcome::Passed)
        ->and($fix->fixed_by)->toBe('7')
        ->and($widget->refresh()->broken)->toBeFalse();

    $result = resultFor($run->refresh(), FailsWhenBroken::class);
    expect($result->outcome)->toBe(Outcome::Passed)
        ->and($result->fixable)->toBeFalse()
        ->and($result->fixed_at)->not->toBeNull()
        ->and($run->outcome->value)->toBe('passed')
        ->and($run->failed_count)->toBe(0)
        ->and($run->fixed_at)->not->toBeNull()
        ->and(Diagnostics::gate($widget)->passed())->toBeFalse()
        ->and(Diagnostics::gate($widget)->reason())->toBe('A fix has been applied since the last run.');

    Event::assertDispatched(CheckFixed::class, fn ($e) => $e->fix->is($fix));

    // A fresh run clears it.
    expect(Diagnostics::gate($widget)->run()->is($run))->toBeTrue();
    Diagnostics::run($widget);
    expect(Diagnostics::gate($widget)->passed())->toBeTrue();
});

it('records a fix that stops itself, and changes nothing', function () {
    $widget = $this->widget(['broken' => true, 'name' => 'Stubborn']);
    $run = Diagnostics::run($widget);

    $fix = Diagnostics::fix(resultFor($run, FailsWhenBroken::class));

    expect($fix->status)->toBe(FixStatus::Failed)
        ->and($fix->message)->toBe('This widget will not be mended')
        ->and($fix->resolved())->toBeFalse()
        ->and($run->refresh()->fixed_at)->toBeNull()
        ->and(resultFor($run, FailsWhenBroken::class)->fixable)->toBeTrue();
});

it('rolls back a fix that throws', function () {
    $widget = $this->widget(['broken' => true, 'name' => 'Volatile', 'colour' => 'green']);
    $run = Diagnostics::run($widget);

    $fix = Diagnostics::fix(resultFor($run, FailsWhenBroken::class));

    expect($fix->status)->toBe(FixStatus::Errored)
        ->and($fix->error)->toContain('Boom')
        ->and($widget->refresh()->broken)->toBeTrue()
        ->and($widget->colour)->toBe('green');
});

it('reports a fix that ran but did not cure the problem', function () {
    $run = Diagnostics::run($this->widget(['broken' => true, 'name' => 'Relapsing']));

    $fix = Diagnostics::fix(resultFor($run, FailsWhenBroken::class));

    expect($fix->status)->toBe(FixStatus::Succeeded)
        ->and($fix->resolved())->toBeFalse()
        ->and($fix->outcome_after)->toBe(Outcome::Failed)
        ->and(resultFor($run, FailsWhenBroken::class)->fixable)->toBeTrue();
});

it('validates answers against the questions and hands the fix typed values', function () {
    $widget = $this->widget(['warn' => true]);
    $run = Diagnostics::run($widget);
    $result = resultFor($run, WarnsWhenAsked::class);

    expect(fn () => Diagnostics::fix($result, ['name' => '', 'colour' => 'green']))
        ->toThrow(ValidationException::class);

    try {
        Diagnostics::fix($result, ['name' => '', 'colour' => 'green', 'coats' => 0]);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toEqualCanonicalizing(['name', 'colour', 'coats']);
    }

    $fix = Diagnostics::fix($result, ['name' => 'Defiant', 'colour' => 'blue', 'coats' => '3', 'varnish' => '1', 'ignored' => 'x']);

    expect($fix->message)->toBe('blue, 3 coats, varnished')
        ->and($fix->answers)->toBe(['name' => 'Defiant', 'colour' => 'blue', 'coats' => 3, 'varnish' => true])
        ->and($widget->refresh()->name)->toBe('Defiant');
});

it('refuses fixes that are not on offer', function () {
    $clean = Diagnostics::run($this->widget());

    expect(fn () => Diagnostics::fix(resultFor($clean, FailsWhenBroken::class)))
        ->toThrow(FixUnavailable::class, 'There is no fix on offer for this result.');
});

it('runs the suite\'s afterFix hook inside the fix, and rolls back if it throws', function () {
    $seen = [];
    Diagnostics::get('widgets')->afterFix(function (Widget $widget, DiagnosticResult $result, array $answers) use (&$seen) {
        $seen[] = [$widget->broken, $result->check_class];
        if ($widget->colour === 'doomed') {
            throw new RuntimeException('After-fix failed');
        }
    });

    $run = Diagnostics::run($this->widget(['broken' => true]));
    Diagnostics::fix(resultFor($run, FailsWhenBroken::class));
    expect($seen)->toBe([[false, FailsWhenBroken::class]]);

    $doomed = $this->widget(['broken' => true, 'colour' => 'doomed']);
    $fix = Diagnostics::fix(resultFor(Diagnostics::run($doomed), FailsWhenBroken::class));
    expect($fix->status)->toBe(FixStatus::Errored)
        ->and($doomed->refresh()->broken)->toBeTrue();
});

it('builds questions fluently or from arrays', function () {
    $q = Question::fromArray(['name' => 'rate', 'type' => 'number', 'label' => 'Rate', 'rules' => 'required|min:1', 'suffix' => 'p', 'default' => 20]);

    expect($q->toArray())->toMatchArray(['name' => 'rate', 'type' => 'number', 'required' => true, 'suffix' => 'p', 'default' => 20])
        ->and($q->validationRules())->toBe(['required', 'min:1', 'numeric'])
        ->and(Question::select('c', 'C', [1 => 'One', 2 => 'Two'])->toArray()['options'])->toBe([['value' => '1', 'label' => 'One'], ['value' => '2', 'label' => 'Two']])
        ->and(fn () => Question::make('slider', 'x', 'X'))->toThrow(InvalidArgumentException::class);
});

it('serves the questions and applies fixes over HTTP', function () {
    $widget = $this->widget(['warn' => true]);
    $run = Diagnostics::run($widget);
    $result = resultFor($run, WarnsWhenAsked::class);

    $this->actingAs($this->user())
        ->getJson("/diagnostics/widgets/{$widget->id}")
        ->assertJsonPath('suite.can_fix', true)
        ->assertJsonPath('latest_run.results.2.fixable', true)
        ->assertJsonPath('latest_run.results.2.fix_label', 'Answer the warning');

    $this->actingAs($this->user())
        ->getJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix")
        ->assertOk()
        ->assertJsonPath('label', 'Answer the warning')
        ->assertJsonPath('description', 'Rename the widget and choose a colour.')
        ->assertJsonCount(4, 'questions')
        ->assertJsonPath('questions.0.default', 'Voyager')
        ->assertJsonPath('questions.1.options.1.label', 'Blue');

    $this->actingAs($this->user())
        ->postJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix", ['answers' => ['name' => '']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'colour']);

    $this->actingAs($this->user())
        ->postJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix", ['answers' => ['name' => 'Enterprise', 'colour' => 'red']])
        ->assertOk()
        ->assertJsonPath('fix.status', 'succeeded')
        ->assertJsonPath('fix.resolved', true)
        ->assertJsonPath('result.outcome', 'passed')
        ->assertJsonPath('run.outcome', 'passed')
        ->assertJsonPath('gate.fresh', false);

    $this->actingAs($this->user())
        ->postJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix", ['answers' => ['name' => 'Again', 'colour' => 'red']])
        ->assertStatus(409);
});

it('applies the suite\'s fix authorisation', function () {
    Diagnostics::get('widgets')->authorizeFix(fn ($user) => $user?->id === 1);
    $widget = $this->widget(['broken' => true]);
    $run = Diagnostics::run($widget);
    $result = resultFor($run, FailsWhenBroken::class);

    $this->actingAs($this->user(2))
        ->getJson("/diagnostics/widgets/{$widget->id}")
        ->assertJsonPath('suite.can_fix', false);

    $this->actingAs($this->user(2))
        ->postJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix")
        ->assertForbidden();

    $this->actingAs($this->user(1))
        ->postJson("/diagnostics/runs/{$run->id}/results/{$result->id}/fix")
        ->assertOk();
});

it('applies a fix from the console', function () {
    $widget = $this->widget(['warn' => true]);
    $result = resultFor(Diagnostics::run($widget), WarnsWhenAsked::class);

    $this->artisan('diagnostics:fix', ['result' => $result->id, '--answer' => ['name=Orville', 'colour=purple']])
        ->expectsOutputToContain('colour')
        ->assertExitCode(2);

    $this->artisan('diagnostics:fix', ['result' => $result->id, '--answer' => ['name=Orville', 'colour=blue']])
        ->expectsOutputToContain('Fixed.')
        ->assertExitCode(0);

    expect($widget->refresh()->name)->toBe('Orville');
});

it('shows the message of an exception the suite marks as written for users, and rolls back', function () {
    Diagnostics::get('widgets')
        ->fixFailsOn([DomainException::class])
        ->afterFix(fn (Widget $widget) => throw new DomainException('Widgets cannot be mended while they are on loan.'));

    $widget = $this->widget(['broken' => true]);
    $fix = Diagnostics::fix(resultFor(Diagnostics::run($widget), FailsWhenBroken::class));

    expect($fix->status)->toBe(FixStatus::Failed)
        ->and($fix->message)->toBe('Widgets cannot be mended while they are on loan.')
        ->and($fix->error)->toBeNull()
        ->and($widget->refresh()->broken)->toBeTrue();
});

it('still records other exceptions as errors when some are marked for users', function () {
    Diagnostics::get('widgets')
        ->fixFailsOn([DomainException::class])
        ->afterFix(fn () => throw new LogicException('Internal detail'));

    $fix = Diagnostics::fix(resultFor(Diagnostics::run($this->widget(['broken' => true])), FailsWhenBroken::class));

    expect($fix->status)->toBe(FixStatus::Errored)
        ->and($fix->message)->toBe('The fix could not be applied.');
});
