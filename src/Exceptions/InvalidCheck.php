<?php

namespace Mralston\Diagnostics\Exceptions;

use LogicException;

class InvalidCheck extends LogicException
{
    public static function missingRun(string $class): self
    {
        return new self("Check [{$class}] has no public run() method taking the subject.");
    }
}
