<?php

namespace Mralston\Diagnostics\Enums;

enum FixStatus: string
{
    /** The fix ran to the end. The re-check says whether it worked. */
    case Succeeded = 'succeeded';

    /** The fix stopped itself with a reason for the user. */
    case Failed = 'failed';

    /** The fix threw something unexpected. */
    case Errored = 'errored';
}
