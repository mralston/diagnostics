<?php

namespace Mralston\Diagnostics;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Mralston\Diagnostics\Discovery\CheckDefinition;
use Mralston\Diagnostics\Discovery\CheckDiscovery;

/**
 * A named set of checks for one subject class. Built fluently by the host and
 * registered with the manager; read by everything else.
 */
final class Suite
{
    private ?string $label = null;

    private ?string $subjectClass = null;

    private ?string $checksPath = null;

    private ?string $checksNamespace = null;

    private ?Closure $authorize = null;

    private ?Closure $resolveSubject = null;

    private ?Closure $authorizeFix = null;

    private ?Closure $afterFix = null;

    /** @var string[] */
    private array $categories = [];

    private ?string $queue = null;

    private ?string $connection = null;

    private ?int $parallelBatches = null;

    /** @var string[] */
    private array $disabled = [];

    /** @var string[]|null */
    private ?array $acceptable = null;

    /** @var Collection<int, CheckDefinition>|null */
    private ?Collection $checks = null;

    public function __construct(private readonly string $key)
    {
    }

    // -- Registration -------------------------------------------------------------------------

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /** @param  class-string<Model>  $class */
    public function subject(string $class): self
    {
        $this->subjectClass = $class;

        return $this;
    }

    /**
     * Where the checks live: a directory and the namespace that maps to it,
     * PSR-4 style. Sub-directories become categories.
     */
    public function checksIn(string $path, string $namespace): self
    {
        $this->checksPath = rtrim($path, '/\\');
        $this->checksNamespace = trim($namespace, '\\');

        return $this;
    }

    /** @param  Closure(mixed $user, Model $subject): bool  $callback */
    public function authorize(Closure $callback): self
    {
        $this->authorize = $callback;

        return $this;
    }

    /**
     * Who may apply fixes. Fixes change the record, so a host will usually ask
     * for more than it does to view a run. Defaults to the authorize callback.
     *
     * @param  Closure(mixed $user, Model $subject): bool  $callback
     */
    public function authorizeFix(Closure $callback): self
    {
        $this->authorizeFix = $callback;

        return $this;
    }

    /**
     * Runs after every successful fix, inside the fix's transaction, with a
     * freshly loaded subject: the place for the host's usual after-save work,
     * such as recalculating totals or writing an activity log. Throwing here
     * rolls the fix back.
     *
     * @param  Closure(Model $subject, \Mralston\Diagnostics\Models\DiagnosticResult $result, array $answers): void  $callback
     */
    public function afterFix(Closure $callback): self
    {
        $this->afterFix = $callback;

        return $this;
    }

    /** @param  Closure(string|int $id): ?Model  $callback */
    public function resolveSubject(Closure $callback): self
    {
        $this->resolveSubject = $callback;

        return $this;
    }

    /** Display and run order of categories. Unlisted categories follow, alphabetically. */
    public function categories(array $categories): self
    {
        $this->categories = array_values($categories);

        return $this;
    }

    public function queue(?string $queue, ?string $connection = null): self
    {
        $this->queue = $queue;
        $this->connection = $connection;

        return $this;
    }

    public function parallelBatches(?int $count): self
    {
        $this->parallelBatches = $count;

        return $this;
    }

    /** @param  string[]  $classes */
    public function disable(array $classes): self
    {
        $this->disabled = array_merge($this->disabled, $classes);

        return $this;
    }

    /**
     * Run outcomes that open this suite's gate and let its chain job continue. Defaults to
     * config('diagnostics.chain.acceptable'): passed and passed_with_warnings.
     *
     * @param  string[]  $outcomes  Any of passed, passed_with_warnings, failed, errored.
     */
    public function acceptable(array $outcomes): self
    {
        $this->acceptable = array_values($outcomes);

        return $this;
    }

    public function register(): self
    {
        app(DiagnosticsManager::class)->register($this);

        return $this;
    }

    // -- Reading ------------------------------------------------------------------------------

    public function getKey(): string
    {
        return $this->key;
    }

    /** Config wins over the registration, which wins over a headline of the key. */
    public function getLabel(): string
    {
        return $this->override('label') ?? $this->label ?? Str::headline($this->key);
    }

    /** @return class-string<Model> */
    public function getSubjectClass(): string
    {
        return $this->subjectClass ?? throw new \LogicException("Suite [{$this->key}] has no subject class.");
    }

    public function getChecksPath(): ?string
    {
        return $this->checksPath;
    }

    public function getChecksNamespace(): ?string
    {
        return $this->checksNamespace;
    }

    /** @return string[] */
    public function getCategoryOrder(): array
    {
        return $this->categories;
    }

    public function queueName(): ?string
    {
        return $this->override('queue') ?? $this->queue ?? config('diagnostics.queue.name');
    }

    public function queueConnection(): ?string
    {
        return $this->override('connection') ?? $this->connection ?? config('diagnostics.queue.connection');
    }

    public function parallelBatchCount(): int
    {
        return max(1, (int) ($this->override('parallel_batches') ?? $this->parallelBatches ?? config('diagnostics.parallel_batches', 3)));
    }

    /** @return string[] */
    public function disabledChecks(): array
    {
        return array_values(array_unique(array_merge($this->disabled, (array) $this->override('disabled'))));
    }

    /**
     * Config wins over the registration, which wins over the package default.
     *
     * @return string[]
     */
    public function acceptableOutcomes(): array
    {
        return array_values((array) ($this->override('acceptable')
            ?? $this->acceptable
            ?? config('diagnostics.chain.acceptable', ['passed', 'passed_with_warnings'])));
    }

    /** @return Collection<int, CheckDefinition> */
    public function checks(): Collection
    {
        return $this->checks ??= app(CheckDiscovery::class)->discover($this);
    }

    public function forgetChecks(): void
    {
        $this->checks = null;
    }

    public function authorizes(mixed $user, Model $subject): bool
    {
        if ($this->authorize === null) {
            return $user !== null;
        }

        return (bool) ($this->authorize)($user, $subject);
    }

    public function authorizesFix(mixed $user, Model $subject): bool
    {
        if ($this->authorizeFix === null) {
            return $this->authorizes($user, $subject);
        }

        return (bool) ($this->authorizeFix)($user, $subject);
    }

    public function runAfterFix(Model $subject, \Mralston\Diagnostics\Models\DiagnosticResult $result, array $answers): void
    {
        if ($this->afterFix !== null) {
            ($this->afterFix)($subject, $result, $answers);
        }
    }

    public function subjectUsesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->getSubjectClass()), true);
    }

    /**
     * A query for subjects that includes soft-deleted rows, so history and
     * channel authorisation still resolve after a subject is trashed.
     */
    public function subjectQuery(): Builder
    {
        $class = $this->getSubjectClass();
        $query = $class::query();

        if ($this->subjectUsesSoftDeletes()) {
            $query->withTrashed();
        }

        return $query;
    }

    public function findSubject(string|int $id): ?Model
    {
        if ($this->resolveSubject !== null) {
            return ($this->resolveSubject)($id);
        }

        return $this->subjectQuery()->find($id);
    }

    private function override(string $option): mixed
    {
        return config("diagnostics.suites.{$this->key}.{$option}");
    }
}
