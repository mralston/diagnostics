<?php

namespace Mralston\Diagnostics;

use Illuminate\Contracts\Support\Arrayable;
use Mralston\Diagnostics\Enums\Outcome;

/**
 * What a check returns. One result per check, however many things it looked
 * at: the detail goes in findings, so the summary counts stay per check.
 */
final class Result implements Arrayable
{
    /** @var Finding[] */
    private array $findings = [];

    public function __construct(
        public readonly Outcome $outcome,
        public ?string $summary = null,
    ) {
    }

    public static function pass(?string $summary = null): self
    {
        return new self(Outcome::Passed, $summary);
    }

    public static function warn(?string $summary = null): self
    {
        return new self(Outcome::Warning, $summary);
    }

    public static function fail(?string $summary = null): self
    {
        return new self(Outcome::Failed, $summary);
    }

    public static function skip(?string $summary = null): self
    {
        return new self(Outcome::Skipped, $summary);
    }

    public function finding(string $message, array $data = []): self
    {
        $this->findings[] = new Finding($message, $data);

        return $this;
    }

    /**
     * @param  iterable<int, Finding|string|array{message: string, data?: array}>  $findings
     */
    public function findings(iterable $findings): self
    {
        foreach ($findings as $finding) {
            $this->findings[] = match (true) {
                $finding instanceof Finding => $finding,
                is_string($finding) => new Finding($finding),
                default => Finding::fromArray((array) $finding),
            };
        }

        return $this;
    }

    /** @return Finding[] */
    public function getFindings(): array
    {
        return $this->findings;
    }

    public function toArray(): array
    {
        return [
            'outcome' => $this->outcome->value,
            'summary' => $this->summary,
            'findings' => array_map(fn (Finding $f) => $f->toArray(), $this->findings),
        ];
    }
}
