<?php

namespace Mralston\Diagnostics\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Widget extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'broken' => 'boolean',
        'explode' => 'boolean',
        'advisory_broken' => 'boolean',
        'warn' => 'boolean',
        'secret' => 'boolean',
    ];
}
