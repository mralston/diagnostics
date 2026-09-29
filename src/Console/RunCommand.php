<?php

namespace Mralston\Diagnostics\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Mralston\Diagnostics\DiagnosticsManager;
use Mralston\Diagnostics\Enums\Executor;
use Mralston\Diagnostics\Enums\Outcome;
use Mralston\Diagnostics\Enums\ResultStatus;
use Mralston\Diagnostics\Enums\RunOutcome;
use Mralston\Diagnostics\Enums\Trigger;
use Mralston\Diagnostics\Events\CheckCompleted;
use Mralston\Diagnostics\Events\RunStarted;
use Mralston\Diagnostics\Exceptions\UnknownSuite;
use Mralston\Diagnostics\Http\Resources\RunResource;
use Mralston\Diagnostics\Models\DiagnosticResult;
use Mralston\Diagnostics\Models\DiagnosticRun;
use Mralston\Diagnostics\Runs\Dispatcher;

/**
 * Runs a suite from the console. In-process by default, printing each result
 * as the package's own events fire; with --queue the run is handed to the
 * workers and the results table is tailed instead.
 */
class RunCommand extends Command
{
    protected $signature = 'diagnostics:run
        {suite : The suite key}
        {subject : The subject id}
        {--queue : Dispatch to the queue workers and tail the results}
        {--no-wait : With --queue, print the run id and exit without waiting}
        {--json : Print the finished run as JSON instead of live lines}
        {--no-broadcast : Do not broadcast events from this run}
        {--fail-on-warnings : Exit 1 when the run passed with warnings}';

    protected $description = 'Run a diagnostics suite against one subject';

    private ?string $lastCategory = null;

    public function handle(DiagnosticsManager $manager, Dispatcher $dispatcher): int
    {
        try {
            $suite = $manager->get((string) $this->argument('suite'));
        } catch (UnknownSuite $e) {
            $this->components->error($e->getMessage());

            return 2;
        }

        $subject = $suite->findSubject((string) $this->argument('subject'));

        if ($subject === null) {
            $this->components->error(sprintf('No %s found with id %s.', class_basename($suite->getSubjectClass()), $this->argument('subject')));

            return 2;
        }

        if ($this->option('no-broadcast')) {
            config(['diagnostics.broadcast.enabled' => false]);
        }

        $live = ! $this->option('json');

        if ($this->option('queue')) {
            $run = $dispatcher->start($suite, $subject, Executor::Queued, Trigger::Artisan)->run;

            if ($this->option('no-wait')) {
                $this->line((string) $run->id);

                return 0;
            }

            if ($live) {
                $this->header($run);
            }

            $run = $this->tail($run, $live);

            if ($run === null) {
                return 2;
            }
        } else {
            if ($live) {
                Event::listen(RunStarted::class, fn (RunStarted $e) => $this->header($e->run));
                Event::listen(CheckCompleted::class, fn (CheckCompleted $e) => $this->printResult($e->result, true));
            }

            $run = $dispatcher->start($suite, $subject, Executor::Sync, Trigger::Artisan)->run;
        }

        if ($live) {
            $this->summary($run);
        } else {
            $this->line((string) json_encode((new RunResource($run->load('results')))->toArray(request()), JSON_PRETTY_PRINT));
        }

        return match ($run->outcome) {
            RunOutcome::Passed => 0,
            RunOutcome::PassedWithWarnings => $this->option('fail-on-warnings') ? 1 : 0,
            RunOutcome::Failed => 1,
            default => 2,
        };
    }

    private function header(DiagnosticRun $run): void
    {
        $this->newLine();
        $this->line(sprintf(
            '<options=bold>%s: %s #%s</> <fg=gray>(run %d, %d %s)</>',
            $run->suiteDefinition()->getLabel(),
            class_basename($run->subject_type),
            $run->subject_id,
            $run->id,
            $run->total_checks,
            $run->total_checks === 1 ? 'check' : 'checks',
        ));
        $this->newLine();
    }

    /** Poll the results table until the run is terminal. Null when it went stuck. */
    private function tail(DiagnosticRun $run, bool $live): ?DiagnosticRun
    {
        $printed = [];
        $stuckAfter = (int) config('diagnostics.stuck_after_minutes', 15);

        while (true) {
            $results = DiagnosticResult::where('run_id', $run->id)
                ->where('status', ResultStatus::Completed->value)
                ->whereNotIn('id', $printed)
                ->orderBy('finished_at')
                ->orderBy('position')
                ->get();

            foreach ($results as $result) {
                $printed[] = $result->id;

                if ($live) {
                    $this->printResult($result, false);
                }
            }

            $run->refresh();

            if ($run->isTerminal()) {
                return $run;
            }

            $lastActivity = $run->last_activity_at ?? $run->created_at;

            if ($lastActivity !== null && $lastActivity->lt(now()->subMinutes($stuckAfter))) {
                $this->components->error(sprintf('Run %d has had no activity for %d minutes. Is a worker listening on the "%s" queue?', $run->id, $stuckAfter, $run->suiteDefinition()->queueName() ?? 'default'));

                return null;
            }

            if ($run->waitingForWorker() && $live && ! isset($warned)) {
                $warned = true;
                $this->line(sprintf(' <fg=gray>Waiting for a worker on the "%s" queue…</>', $run->suiteDefinition()->queueName() ?? 'default'));
            }

            usleep(500_000);
        }
    }

    private function printResult(DiagnosticResult $result, bool $groupByCategory): void
    {
        if ($groupByCategory && $result->category !== $this->lastCategory) {
            $this->lastCategory = $result->category;
            $this->line(' <options=bold>'.$result->category.'</>');
        }

        $tag = match ($result->outcome) {
            Outcome::Passed => '<fg=green>PASS</>',
            Outcome::Warning => '<fg=yellow>WARN</>',
            Outcome::Failed => '<fg=red>FAIL</>',
            Outcome::Skipped => '<fg=gray>SKIP</>',
            default => '<fg=magenta>ERR </>',
        };

        $title = $groupByCategory ? $result->title : sprintf('[%s] %s', $result->category, $result->title);
        $trailer = $result->outcome === Outcome::Skipped && $result->summary
            ? $result->summary
            : sprintf('%dms', (int) $result->duration_ms);

        $width = max(10, 72 - mb_strlen($title) - mb_strlen($trailer));
        $this->line(sprintf('  %s  %s <fg=gray>%s %s</>', $tag, $title, str_repeat('.', $width), $trailer));

        if ($result->outcome !== Outcome::Passed && $result->outcome !== Outcome::Skipped) {
            if ($result->summary) {
                $this->line('        <fg=gray>'.$result->summary.'</>');
            }

            foreach (array_slice($result->findings ?? [], 0, 10) as $finding) {
                $this->line('        <fg=gray>- '.($finding['message'] ?? '').'</>');
            }

            if ($result->error && $result->error !== $result->summary) {
                $this->line('        <fg=magenta>'.$result->error.'</>');
            }
        }
    }

    private function summary(DiagnosticRun $run): void
    {
        $counts = $run->counts();

        $this->newLine();
        $this->line(sprintf(
            ' <fg=green>%d passed</>, <fg=yellow>%d %s</>, <fg=red>%d failed</>, <fg=gray>%d skipped</>, <fg=magenta>%d errored</> in %.1fs',
            $counts['passed'],
            $counts['warning'],
            $counts['warning'] === 1 ? 'warning' : 'warnings',
            $counts['failed'],
            $counts['skipped'],
            $counts['errored'],
            ($run->duration_ms ?? 0) / 1000,
        ));

        $colour = match ($run->outcome) {
            RunOutcome::Passed => 'green',
            RunOutcome::PassedWithWarnings => 'yellow',
            RunOutcome::Failed => 'red',
            default => 'magenta',
        };

        $this->line(sprintf(' Outcome: <fg=%s;options=bold>%s</>', $colour, strtoupper($run->outcome?->label() ?? $run->status->value)));
        $this->newLine();
    }
}
