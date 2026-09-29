<?php

namespace Mralston\Diagnostics\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Events are broadcast synchronously from inside the run, so a broadcaster
 * that is down would otherwise turn every check into an error. Progress
 * events are a courtesy; the database is the record. Failures are logged and
 * swallowed.
 */
class EventEmitter
{
    public static function emit(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $e) {
            Log::warning('Diagnostics event could not be dispatched.', [
                'event' => $event::class,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
