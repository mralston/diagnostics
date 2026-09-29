<?php

namespace Mralston\Diagnostics\Tests;

use Illuminate\Foundation\Auth\User as Authenticatable;

class TestUser extends Authenticatable
{
    protected $guarded = [];
}
