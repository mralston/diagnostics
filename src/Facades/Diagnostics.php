<?php

namespace Mralston\Diagnostics\Facades;

use Illuminate\Support\Facades\Facade;
use Mralston\Diagnostics\DiagnosticsManager;

/**
 * @method static \Mralston\Diagnostics\Suite suite(string $key)
 * @method static void register(\Mralston\Diagnostics\Suite $suite)
 * @method static bool has(string $key)
 * @method static \Mralston\Diagnostics\Suite get(string $key)
 * @method static array<string, \Mralston\Diagnostics\Suite> suites()
 * @method static \Mralston\Diagnostics\Suite suiteFor(\Illuminate\Database\Eloquent\Model|string $subject)
 * @method static \Mralston\Diagnostics\Models\DiagnosticRun start(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null, \Mralston\Diagnostics\Enums\Trigger $trigger = \Mralston\Diagnostics\Enums\Trigger::Code, ?string $triggeredById = null)
 * @method static \Mralston\Diagnostics\Models\DiagnosticRun run(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null, \Mralston\Diagnostics\Enums\Trigger $trigger = \Mralston\Diagnostics\Enums\Trigger::Code, ?string $triggeredById = null)
 * @method static \Mralston\Diagnostics\Models\DiagnosticRun dispatch(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null, \Mralston\Diagnostics\Enums\Trigger $trigger = \Mralston\Diagnostics\Enums\Trigger::Job, ?string $triggeredById = null)
 * @method static \Mralston\Diagnostics\Models\DiagnosticRun|null latestRun(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null)
 * @method static \Mralston\Diagnostics\Models\DiagnosticRun|null inFlight(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null)
 * @method static \Mralston\Diagnostics\Data\RunStatusData|null status(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null)
 * @method static \Mralston\Diagnostics\Runs\Gate gate(\Illuminate\Database\Eloquent\Model $subject, ?string $suite = null)
 *
 * @see DiagnosticsManager
 */
class Diagnostics extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return DiagnosticsManager::class;
    }
}
