<?php

namespace Mralston\Diagnostics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Exceptions\FixUnavailable;
use Mralston\Diagnostics\Fixes\Fixer;
use Mralston\Diagnostics\Fixes\Question;
use Mralston\Diagnostics\Http\Resources\ResultResource;
use Mralston\Diagnostics\Http\Resources\RunResource;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FixController
{
    /** What the fix will ask, with defaults read from the record as it is now. */
    public function show(Request $request, DiagnosticsManager $manager, Fixer $fixer, DiagnosticRun $run, DiagnosticResult $result): JsonResponse
    {
        $this->authorizeFix($request, $manager, $run, $result);

        try {
            return response()->json([
                'result_id' => $result->id,
                'title' => $result->title,
                'label' => $result->fix_label,
                'description' => $fixer->description($result),
                'questions' => array_map(fn (Question $q) => $q->toArray(), $fixer->questions($result)),
            ]);
        } catch (FixUnavailable $e) {
            throw new HttpException(409, $e->getMessage());
        }
    }

    /**
     * Applies the fix. A fix that ran but stopped, or threw, is still a 200:
     * the attempt is recorded and its message is for the user. Only answers
     * that fail validation (422) or a fix that is not on offer (409) are errors.
     */
    public function store(Request $request, DiagnosticsManager $manager, Fixer $fixer, DiagnosticRun $run, DiagnosticResult $result): JsonResponse
    {
        $subject = $this->authorizeFix($request, $manager, $run, $result);

        try {
            $fix = $fixer->fix($result, (array) $request->input('answers', []), $request->user());
        } catch (FixUnavailable $e) {
            throw new HttpException(409, $e->getMessage());
        }

        return response()->json([
            'fix' => $fix->toArray(),
            'result' => (new ResultResource($fix->result->refresh()))->toArray($request),
            'run' => (new RunResource($run->refresh()->load('results')))->toArray($request),
            'gate' => $manager->gate($subject->refresh(), $run->suite)->toArray(),
        ]);
    }

    private function authorizeFix(Request $request, DiagnosticsManager $manager, DiagnosticRun $run, DiagnosticResult $result): \Illuminate\Database\Eloquent\Model
    {
        $subject = RunController::authorizeRun($request, $manager, $run);

        if ($result->run_id !== $run->id) {
            throw new HttpException(404, 'Result not found on this run.');
        }

        if (! $manager->get($run->suite)->authorizesFix($request->user(), $subject)) {
            throw new HttpException(403, 'You may not apply fixes to this record.');
        }

        return $subject;
    }
}
