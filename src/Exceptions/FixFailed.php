<?php

namespace Mralston\Diagnostics\Exceptions;

use RuntimeException;

/**
 * Thrown from a check's fix() when the fix cannot be applied for a reason the
 * user should read, such as "there is no Flux version of this battery". The
 * message is shown as it is, and anything the fix wrote is rolled back.
 */
class FixFailed extends RuntimeException
{
}
