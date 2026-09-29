<?php

use Illuminate\Support\Facades\Broadcast;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Models\DiagnosticRun;

/*
 * One private channel per run. Whether a user may listen is the suite's
 * decision, made against the run's subject.
 */
Broadcast::channel('diagnostics.run.{runId}', function ($user, $runId) {
    $run = DiagnosticRun::find($runId);
    $manager = app(DiagnosticsManager::class);

    if ($run === null || ! $manager->has($run->suite)) {
        return false;
    }

    $suite = $manager->get($run->suite);
    $subject = $suite->findSubject($run->subject_id);

    return $subject !== null && $suite->authorizes($user, $subject);
});
