<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Where queued runs are executed. Null means the application's default
    | connection and queue, which suits a host with one worker pool. A host
    | with a dedicated pool, possibly on another server, names it here and
    | makes sure a worker (or Horizon supervisor) listens on it.
    |
    */

    'queue' => [
        'connection' => env('DIAGNOSTICS_QUEUE_CONNECTION'),
        'name' => env('DIAGNOSTICS_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Execution
    |--------------------------------------------------------------------------
    |
    | parallel_batches: how many jobs a queued run is split across. Checks
    | that are not parallel-safe run in one extra batch after those finish.
    |
    | default_executor: what Diagnostics::start() and the HTTP API use.
    | "queued" fans out to workers; "sync" runs in the calling process.
    |
    | check_timeout is advisory (PHP cannot interrupt a method); a batch job's
    | timeout is the sum of its checks' timeouts, capped by batch_timeout_cap.
    |
    */

    'parallel_batches' => (int) env('DIAGNOSTICS_PARALLEL_BATCHES', 3),
    'default_executor' => env('DIAGNOSTICS_EXECUTOR', 'queued'),
    'check_timeout' => 60,
    'batch_timeout_cap' => 900,

    /*
    |--------------------------------------------------------------------------
    | Chained runs
    |--------------------------------------------------------------------------
    |
    | RunDiagnosis runs a suite in-process as a step in Bus::chain() and stops
    | the chain when the outcome is not in "acceptable"; the same list opens a
    | suite's gate. "errored" is left out by default, so a run that could not
    | check something fails closed. A suite can choose its own list with
    | ->acceptable([...]) or the "acceptable" key under suites below.
    |
    */

    'chain' => [
        'timeout' => 600,
        'acceptable' => ['passed', 'passed_with_warnings'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    */

    // A second request to run while one is in flight returns the in-flight run.
    'coalesce_in_flight' => true,

    // A run with no activity for this long is abandoned by diagnostics:reap.
    'stuck_after_minutes' => 15,

    // The API flags a run as waiting for a worker after this many seconds
    // with nothing started, so a UI can say so.
    'waiting_for_worker_after' => 10,

    // A completed run is stale once the subject changes or this long passes.
    'freshness' => [
        'ttl_minutes' => 1440,
    ],

    // Runs older than this are removed by diagnostics:prune.
    'retention_days' => 30,

    /*
    |--------------------------------------------------------------------------
    | Broadcasting
    |--------------------------------------------------------------------------
    |
    | Events are broadcast immediately (ShouldBroadcastNow) on the private
    | channel diagnostics.run.{id}. Finding messages are trimmed to keep each
    | payload inside Pusher's 10 KB limit; the full result is always in the
    | database and the API.
    |
    */

    'broadcast' => [
        'enabled' => env('DIAGNOSTICS_BROADCAST', true),
        'max_findings' => 5,
        'max_message_length' => 200,
        'max_payload_bytes' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP API
    |--------------------------------------------------------------------------
    */

    'routes' => [
        'enabled' => true,
        'prefix' => 'diagnostics',
        'middleware' => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_findings_per_result' => 200,
        'max_findings_bytes' => 65536,
    ],

    // Category given to a check that sits at the root of its suite's directory
    // and does not declare one.
    'default_category' => 'General',

    /*
    |--------------------------------------------------------------------------
    | Per-suite overrides
    |--------------------------------------------------------------------------
    |
    | Keyed by suite key. Anything set here wins over the suite's registration.
    |
    |   'listing-check' => [
    |       'label' => 'Listing Check',
    |       'disabled' => [App\ListingChecks\Photos\FloorPlanPresent::class],
    |       'parallel_batches' => 4,
    |       'queue' => 'diagnostics',
    |       'connection' => 'redis',
    |       'acceptable' => ['passed', 'passed_with_warnings', 'errored'],
    |   ],
    |
    */

    'suites' => [],

];
