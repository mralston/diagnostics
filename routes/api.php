<?php

use Illuminate\Support\Facades\Route;
use Mralston\Diagnostics\Http\Controllers\FixController;
use Mralston\Diagnostics\Http\Controllers\ResultController;
use Mralston\Diagnostics\Http\Controllers\RunController;
use Mralston\Diagnostics\Http\Controllers\SuiteController;
use Mralston\Diagnostics\Http\Middleware\ResolveSuiteSubject;

Route::get('runs/{run}', [RunController::class, 'show'])->name('runs.show');
Route::get('runs/{run}/results/{result}', [ResultController::class, 'show'])->name('results.show');
Route::get('runs/{run}/results/{result}/fix', [FixController::class, 'show'])->name('fixes.show');
Route::post('runs/{run}/results/{result}/fix', [FixController::class, 'store'])->name('fixes.store');

Route::middleware(ResolveSuiteSubject::class)->group(function () {
    Route::get('{suite}/{subject}', [SuiteController::class, 'show'])->name('suite.show');
    Route::get('{suite}/{subject}/runs', [RunController::class, 'index'])->name('runs.index');
    Route::post('{suite}/{subject}/runs', [RunController::class, 'store'])->name('runs.store');
});
