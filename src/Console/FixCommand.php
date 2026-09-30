<?php

namespace Mralston\Diagnostics\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Mralston\Diagnostics\Enums\FixStatus;
use Mralston\Diagnostics\Exceptions\FixUnavailable;
use Mralston\Diagnostics\Fixes\Fixer;
use Mralston\Diagnostics\Models\DiagnosticResult;

class FixCommand extends Command
{
    protected $signature = 'diagnostics:fix
        {result : The id of the result to fix}
        {--answer=* : An answer to one of the fix\'s questions, as name=value}';

    protected $description = 'Apply the fix a check offers for one of its results, then re-check';

    public function handle(Fixer $fixer): int
    {
        $result = DiagnosticResult::find($this->argument('result'));

        if ($result === null) {
            $this->error('No result with that id.');

            return self::FAILURE;
        }

        $answers = [];
        foreach ((array) $this->option('answer') as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $answers[$name] = $value;
        }

        try {
            $fix = $fixer->fix($result, $answers);
        } catch (FixUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $name => $messages) {
                $this->error("{$name}: ".implode(' ', $messages));
            }

            return self::INVALID;
        }

        $line = sprintf('%s: %s', $result->title, $fix->message ?? $fix->status->value);

        match (true) {
            $fix->resolved() => $this->info("Fixed. {$line}"),
            $fix->status === FixStatus::Succeeded => $this->warn(sprintf('The fix ran, but the check is still %s. %s', $fix->outcome_after?->value, $line)),
            default => $this->error($line),
        };

        return $fix->resolved() ? self::SUCCESS : self::FAILURE;
    }
}
