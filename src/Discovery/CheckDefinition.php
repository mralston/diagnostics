<?php

namespace Mralston\Diagnostics\Discovery;

use Illuminate\Contracts\Support\Arrayable;
use Mralston\Diagnostics\Contracts\Check;

/**
 * Everything the engine needs to know about a check without instantiating it
 * again: its class and the metadata read once at discovery.
 */
final class CheckDefinition implements Arrayable
{
    public function __construct(
        public readonly string $class,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $category,
        public readonly bool $canFail,
        public readonly bool $parallelSafe,
        public readonly int $order,
        public readonly ?int $timeout,
        public int $position = 0,
    ) {
    }

    public function make(): Check
    {
        return app($this->class);
    }

    public function toArray(): array
    {
        return [
            'class' => $this->class,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'can_fail' => $this->canFail,
            'parallel_safe' => $this->parallelSafe,
            'order' => $this->order,
            'position' => $this->position,
        ];
    }
}
