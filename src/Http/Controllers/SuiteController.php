<?php

namespace Mralston\Diagnostics\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mralston\Diagnostics\Discovery\CheckDefinition;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Http\Resources\RunResource;
use Mralston\Diagnostics\Suite;

class SuiteController
{
    public function show(Request $request, DiagnosticsManager $manager): JsonResponse
    {
        /** @var Suite $suite */
        $suite = $request->attributes->get('diagnostics.suite');
        /** @var Model $subject */
        $subject = $request->attributes->get('diagnostics.subject');

        $latest = $manager->latestRun($subject, $suite->getKey());

        return response()->json([
            'suite' => [
                'key' => $suite->getKey(),
                'label' => $suite->getLabel(),
                'categories' => $suite->checks()->pluck('category')->unique()->values()->all(),
                'can_fix' => $suite->authorizesFix($request->user(), $subject),
            ],
            'subject' => [
                'type' => $subject->getMorphClass(),
                'id' => (string) $subject->getKey(),
                'updated_at' => $subject->usesTimestamps() ? $subject->{$subject->getUpdatedAtColumn()}?->toIso8601String() : null,
            ],
            'checks' => $suite->checks()->map(fn (CheckDefinition $check) => $check->toArray())->values()->all(),
            'latest_run' => $latest === null ? null : (new RunResource($latest->load('results')))->toArray($request),
            'gate' => $manager->gate($subject, $suite->getKey())->toArray(),
        ]);
    }
}
