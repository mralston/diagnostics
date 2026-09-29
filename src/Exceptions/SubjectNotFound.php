<?php

namespace Mralston\Diagnostics\Exceptions;

use RuntimeException;

class SubjectNotFound extends RuntimeException
{
    public static function for(string $suite, string|int $id): self
    {
        return new self("No subject [{$id}] found for diagnostics suite [{$suite}].");
    }
}
