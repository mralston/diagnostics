<?php

namespace Mralston\Diagnostics;

use Illuminate\Support\Str;
use Mralston\Diagnostics\Contracts\Check as CheckContract;
use Mralston\Diagnostics\Exceptions\FixFailed;
use Mralston\Diagnostics\Fixes\Question;

/**
 * Base class for checks. Declare the metadata as properties and implement
 * run($subject): Result with your own subject type.
 *
 * A check that knows how to put its problem right also implements
 * fix($subject, array $answers): ?string. Its failed and warning results then
 * offer a fix. Anything the fix needs to ask goes in $fixQuestions, or in
 * fixQuestions($subject) when the questions depend on the record.
 */
abstract class Check implements CheckContract
{
    protected string $title = '';

    protected ?string $description = null;

    protected ?string $category = null;

    protected bool $canFail = true;

    protected bool $parallelSafe = true;

    protected int $order = 100;

    protected ?int $timeout = null;

    /** The fix button's label. */
    protected string $fixLabel = 'Fix it';

    /** Shown above the questions, to say what the fix will do. */
    protected ?string $fixDescription = null;

    /** @var array<int, Question|array> */
    protected array $fixQuestions = [];

    public function title(): string
    {
        return $this->title !== '' ? $this->title : Str::headline(class_basename(static::class));
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    public function canFail(): bool
    {
        return $this->canFail;
    }

    public function isParallelSafe(): bool
    {
        return $this->parallelSafe;
    }

    public function order(): int
    {
        return $this->order;
    }

    public function timeout(): ?int
    {
        return $this->timeout;
    }

    public function isFixable(): bool
    {
        return method_exists($this, 'fix');
    }

    public function fixLabel(): string
    {
        return $this->fixLabel;
    }

    public function fixDescription(mixed $subject = null): ?string
    {
        return $this->fixDescription;
    }

    /**
     * What the fix needs to know. Override to build the questions from the
     * record, for example one question per item that needs an answer.
     *
     * @return array<int, Question|array>
     */
    public function fixQuestions(mixed $subject): array
    {
        return $this->fixQuestions;
    }

    /** @return Question[] */
    final public function resolvedFixQuestions(mixed $subject): array
    {
        return array_map(fn ($q) => Question::normalise($q), array_values($this->fixQuestions($subject)));
    }

    /** Stop a fix and tell the user why. Anything already written is rolled back. */
    protected function cannotFix(string $reason): never
    {
        throw new FixFailed($reason);
    }

    protected function pass(?string $summary = null): Result
    {
        return Result::pass($summary);
    }

    protected function warn(?string $summary = null): Result
    {
        return Result::warn($summary);
    }

    protected function fail(?string $summary = null): Result
    {
        return Result::fail($summary);
    }

    protected function skip(?string $summary = null): Result
    {
        return Result::skip($summary);
    }
}
