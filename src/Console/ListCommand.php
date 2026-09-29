<?php

namespace Mralston\Diagnostics\Console;

use Illuminate\Console\Command;
use Mralston\Diagnostics\Discovery\CheckDefinition;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Exceptions\UnknownSuite;
use Mralston\Diagnostics\Suite;

class ListCommand extends Command
{
    protected $signature = 'diagnostics:list {suite? : A suite key; omit to list the suites}';

    protected $description = 'List the registered suites, or the checks discovered for one suite';

    public function handle(DiagnosticsManager $manager): int
    {
        $key = $this->argument('suite');

        if ($key === null) {
            $rows = collect($manager->suites())->map(fn (Suite $suite) => [
                $suite->getKey(),
                $suite->getLabel(),
                $suite->getSubjectClass(),
                $suite->checks()->count(),
                $suite->queueName() ?? '(default)',
                $suite->parallelBatchCount(),
            ])->values()->all();

            if ($rows === []) {
                $this->components->warn('No diagnostics suites are registered.');

                return 0;
            }

            $this->table(['Key', 'Label', 'Subject', 'Checks', 'Queue', 'Batches'], $rows);

            return 0;
        }

        try {
            $suite = $manager->get((string) $key);
        } catch (UnknownSuite $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $batches = $suite->parallelBatchCount();
        $parallelIndex = 0;

        $rows = $suite->checks()->map(function (CheckDefinition $check) use ($batches, &$parallelIndex) {
            $batch = $check->parallelSafe ? (string) (($parallelIndex++ % $batches) + 1) : 'serial';

            return [
                $check->position,
                $check->category,
                $check->title,
                $check->class,
                $check->canFail ? 'yes' : 'advisory',
                $batch,
            ];
        })->all();

        $this->line(sprintf('<options=bold>%s</> (%s): %d checks', $suite->getLabel(), $suite->getKey(), count($rows)));
        $this->table(['#', 'Category', 'Title', 'Class', 'Fails?', 'Batch'], $rows);

        return 0;
    }
}
