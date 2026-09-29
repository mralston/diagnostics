<?php

namespace Mralston\Diagnostics\Console;

use Illuminate\Console\Command;
use Mralston\Diagnostics\Models\DiagnosticRun;

class PruneCommand extends Command
{
    protected $signature = 'diagnostics:prune {--pretend : Report what would be removed without removing it}';

    protected $description = 'Remove diagnostics runs older than the configured retention period';

    public function handle(): int
    {
        return $this->call('model:prune', [
            '--model' => [DiagnosticRun::class],
            '--pretend' => (bool) $this->option('pretend'),
        ]);
    }
}
