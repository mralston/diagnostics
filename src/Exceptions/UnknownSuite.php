<?php

namespace Mralston\Diagnostics\Exceptions;

use InvalidArgumentException;

class UnknownSuite extends InvalidArgumentException
{
    public static function named(string $key): self
    {
        return new self("No diagnostics suite is registered as [{$key}].");
    }

    public static function forSubject(string $class): self
    {
        return new self("No diagnostics suite is registered for [{$class}].");
    }

    public static function ambiguous(string $class, array $keys): self
    {
        return new self(sprintf('More than one diagnostics suite is registered for [%s]: %s. Name the suite.', $class, implode(', ', $keys)));
    }
}
