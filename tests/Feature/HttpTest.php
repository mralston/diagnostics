<?php

use Illuminate\Support\Facades\Queue;
use Mralston\Diagnostics\Facades\Diagnostics;
use Mralston\Diagnostics\Jobs\RunCheckBatch;

it('describes the suite, its checks and the gate for a subject', function () {
    $widget = $this->widget();

    $this->actingAs($this->user())
        ->getJson("/diagnostics/widgets/{$widget->id}")
        ->assertOk()
        ->assertJsonPath('suite.label', 'Widget Doctor')
        ->assertJsonPath('suite.categories', ['Basics', 'Extra'])
        ->assertJsonCount(7, 'checks')
        ->assertJsonPath('latest_run', null)
        ->assertJsonPath('gate.passed', false);
});

it('starts a run and returns it with every result', function () {
    $widget = $this->widget(['warn' => true]);

    $response = $this->actingAs($this->user())
        ->postJson("/diagnostics/widgets/{$widget->id}/runs")
        ->assertCreated()
        ->assertJsonPath('coalesced', false)
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('outcome', 'passed_with_warnings')
        ->assertJsonCount(7, 'results');

    $runId = $response->json('id');

    $this->actingAs($this->user())
        ->getJson("/diagnostics/runs/{$runId}")
        ->assertOk()
        ->assertJsonPath('id', $runId)
        ->assertJsonPath('results.2.outcome', 'warning');

    $resultId = $response->json('results.2.id');

    $this->actingAs($this->user())
        ->getJson("/diagnostics/runs/{$runId}/results/{$resultId}")
        ->assertOk()
        ->assertJsonPath('title', 'Warns when asked')
        ->assertJsonPath('error', null);

    $this->actingAs($this->user())
        ->getJson("/diagnostics/widgets/{$widget->id}/runs")
        ->assertOk()
        ->assertJsonPath('total', 1);
});

it('returns the in-flight run with 200 when asked to start again', function () {
    Queue::fake();
    $widget = $this->widget();

    $first = $this->actingAs($this->user())->postJson("/diagnostics/widgets/{$widget->id}/runs")->assertCreated();
    $second = $this->actingAs($this->user())->postJson("/diagnostics/widgets/{$widget->id}/runs")->assertOk();

    expect($second->json('coalesced'))->toBeTrue()
        ->and($second->json('id'))->toBe($first->json('id'));

    Queue::assertPushed(RunCheckBatch::class, 3);
});

it('applies the suite authorisation and 404s unknown suites and subjects', function () {
    $secret = $this->widget(['secret' => true]);

    // Before any actingAs(), which persists for the rest of the test.
    $this->getJson("/diagnostics/widgets/{$secret->id}")->assertUnauthorized();

    $this->actingAs($this->user())->getJson("/diagnostics/widgets/{$secret->id}")->assertForbidden();
    $this->actingAs($this->user())->getJson('/diagnostics/nope/1')->assertNotFound();
    $this->actingAs($this->user())->getJson('/diagnostics/widgets/999')->assertNotFound();
    $run = Diagnostics::run($secret);
    $this->actingAs($this->user())->getJson("/diagnostics/runs/{$run->id}")->assertForbidden();
});

it('refuses to start a run on a soft-deleted subject but still shows its history', function () {
    $widget = $this->widget();
    $run = Diagnostics::run($widget);
    $widget->delete();

    $this->actingAs($this->user())->postJson("/diagnostics/widgets/{$widget->id}/runs")->assertStatus(422);
    $this->actingAs($this->user())->getJson("/diagnostics/runs/{$run->id}")->assertOk();
});

it('sends plain values for every result field, never a placeholder object', function () {
    $widget = $this->widget(['explode' => true]);

    $run = $this->actingAs($this->user())
        ->postJson("/diagnostics/widgets/{$widget->id}/runs")
        ->assertCreated()
        ->json();

    foreach ($run['results'] as $result) {
        expect($result['error'])->toBeIn([null, 'RuntimeException: Warp core breach']);
    }

    $history = $this->actingAs($this->user())->getJson("/diagnostics/widgets/{$widget->id}/runs")->json('data.0');

    expect($history)->not->toHaveKey('results');
});
