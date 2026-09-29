<?php

namespace Mralston\Diagnostics\Contracts;

/**
 * One check: one question about a subject, answered with one Result.
 *
 * Alongside these methods every check has a public run() method that takes the
 * subject and returns a Result. It is not declared here so that each check can
 * type-hint its own subject class (PHP will not let a subclass narrow an
 * inherited parameter type). Discovery verifies the method exists.
 */
interface Check
{
    public function title(): string;

    public function description(): ?string;

    /** Null means "derive it from the sub-directory the check lives in". */
    public function category(): ?string;

    /** False makes the check advisory: a failure is recorded as a warning. */
    public function canFail(): bool;

    /** False keeps the check out of the parallel batches; it runs alone, afterwards. */
    public function isParallelSafe(): bool;

    /** Sort weight within the category. Lower runs first. */
    public function order(): int;

    /** Advisory per-check timeout in seconds, or null for the configured default. */
    public function timeout(): ?int;
}
