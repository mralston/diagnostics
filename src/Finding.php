<?php

namespace Mralston\Diagnostics;

use Illuminate\Contracts\Support\Arrayable;

final class Finding implements Arrayable
{
    public function __construct(
        public readonly string $message,
        public readonly array $data = [],
    ) {
    }

    public static function fromArray(array $finding): self
    {
        return new self((string) ($finding['message'] ?? ''), (array) ($finding['data'] ?? []));
    }

    public function toArray(): array
    {
        return [
            'message' => $this->message,
            'data' => $this->data,
        ];
    }
}
