<?php

use Illuminate\Support\Facades\Bus;
use Mralston\Diagnostics\Exceptions\DiagnosisFailed;
use Mralston\Diagnostics\Jobs\RunDiagnosis;
use Mralston\Diagnostics\Tests\Fixtures\MarkerJob;

beforeEach(fn () => MarkerJob::$runs = 0);

it('lets the chain continue after a passing run', function () {
    Bus::chain([new RunDiagnosis('widgets', $this->widget()), new MarkerJob])->dispatch();

    expect(MarkerJob::$runs)->toBe(1);
});

it('stops the chain when the run fails, keeping the run', function () {
    $widget = $this->widget(['broken' => true]);
    $caught = null;

    try {
        Bus::chain([new RunDiagnosis('widgets', $widget), new MarkerJob])->dispatch();
    } catch (DiagnosisFailed $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(DiagnosisFailed::class)
        ->and($caught->getMessage())->toContain('Fails when broken')
        ->and($caught->run->failed_count)->toBe(1)
        ->and(MarkerJob::$runs)->toBe(0);
});

it('does not throw when requirePass is off', function () {
    Bus::chain([new RunDiagnosis('widgets', $this->widget(['broken' => true]), requirePass: false), new MarkerJob])->dispatch();

    expect(MarkerJob::$runs)->toBe(1);
});

it('accepts a subject id when the suite is named', function () {
    $widget = $this->widget();

    $job = new RunDiagnosis('widgets', $widget->id);
    Bus::chain([$job, new MarkerJob])->dispatch();

    expect(MarkerJob::$runs)->toBe(1);
});
