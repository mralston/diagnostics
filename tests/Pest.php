<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Mralston\Diagnostics\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
