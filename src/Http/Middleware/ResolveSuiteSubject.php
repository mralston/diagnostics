<?php

namespace Mralston\Diagnostics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Exceptions\UnknownSuite;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Turns the {suite} and {subject} route segments into a Suite and a subject
 * model on the request, and applies the suite's authorisation callback.
 */
class ResolveSuiteSubject
{
    public function __construct(private readonly DiagnosticsManager $manager)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        try {
            $suite = $this->manager->get((string) $request->route('suite'));
        } catch (UnknownSuite) {
            throw new HttpException(404, 'Unknown diagnostics suite.');
        }

        $subject = $suite->findSubject((string) $request->route('subject'));

        if ($subject === null) {
            throw new HttpException(404, 'Subject not found.');
        }

        if (! $suite->authorizes($request->user(), $subject)) {
            throw new HttpException(403, 'You may not run diagnostics on this record.');
        }

        $request->attributes->set('diagnostics.suite', $suite);
        $request->attributes->set('diagnostics.subject', $subject);

        return $next($request);
    }
}
