<?php

namespace Mralston\Diagnostics\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Http\Resources\ResultResource;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ResultController
{
    public function show(Request $request, DiagnosticsManager $manager, DiagnosticRun $run, DiagnosticResult $result): JsonResponse
    {
        RunController::authorizeRun($request, $manager, $run);

        if ($result->run_id !== $run->id) {
            throw new HttpException(404, 'Result not found on this run.');
        }

        return response()->json((new ResultResource($result, true))->toArray($request));
    }
}
