<?php

namespace Mralston\Diagnostics\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Http\Resources\RunResource;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Dispatcher;
use Mralston\Diagnostics\Suite;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RunController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Suite $suite */
        $suite = $request->attributes->get('diagnostics.suite');
        /** @var Model $subject */
        $subject = $request->attributes->get('diagnostics.subject');

        $runs = DiagnosticRun::forSubject($subject)
            ->suite($suite->getKey())
            ->latest('id')
            ->paginate((int) $request->integer('per_page', 10));

        return response()->json([
            'data' => $runs->getCollection()->map(fn (DiagnosticRun $run) => (new RunResource($run, false))->toArray($request))->all(),
            'current_page' => $runs->currentPage(),
            'last_page' => $runs->lastPage(),
            'total' => $runs->total(),
        ]);
    }

    public function store(Request $request, Dispatcher $dispatcher): JsonResponse
    {
        /** @var Suite $suite */
        $suite = $request->attributes->get('diagnostics.suite');
        /** @var Model $subject */
        $subject = $request->attributes->get('diagnostics.subject');

        if ($suite->subjectUsesSoftDeletes() && method_exists($subject, 'trashed') && $subject->trashed()) {
            throw new HttpException(422, 'This record has been deleted.');
        }

        $executor = Executor::tryFrom((string) config('diagnostics.default_executor', 'queued')) ?? Executor::Queued;

        $started = $dispatcher->start(
            $suite,
            $subject,
            $executor,
            Trigger::Http,
            $request->user()?->getAuthIdentifier() !== null ? (string) $request->user()->getAuthIdentifier() : null,
        );

        return response()->json(
            ['coalesced' => $started->coalesced] + (new RunResource($started->run))->toArray($request),
            $started->coalesced ? 200 : 201,
        );
    }

    public function show(Request $request, DiagnosticsManager $manager, DiagnosticRun $run): JsonResponse
    {
        $this->authorizeRun($request, $manager, $run);

        return response()->json((new RunResource($run->load('results')))->toArray($request));
    }

    public static function authorizeRun(Request $request, DiagnosticsManager $manager, DiagnosticRun $run): Model
    {
        if (! $manager->has($run->suite)) {
            throw new HttpException(404, 'Unknown diagnostics suite.');
        }

        $suite = $manager->get($run->suite);
        $subject = $suite->findSubject($run->subject_id);

        if ($subject === null || ! $suite->authorizes($request->user(), $subject)) {
            throw new HttpException(403, 'You may not view this run.');
        }

        return $subject;
    }
}
