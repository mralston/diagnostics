<?php

namespace Mralston\Diagnostics\Exceptions;

use RuntimeException;

/** A fix was asked for that is not on offer: the run is unfinished, or the result is not fixable. */
class FixUnavailable extends RuntimeException
{
}
