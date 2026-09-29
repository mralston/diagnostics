<?php

namespace Mralston\Diagnostics;

use Illuminate\Support\Str;
use Mralston\Diagnostics\Contracts\Check as CheckContract;

/**
 * Base class for checks. Declare the metadata as properties and implement
 * run($subject): Result with your own subject type.
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
