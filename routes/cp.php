<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\JobStatusController;
use ElSchneider\GreenwashingChecker\Http\Controllers\AnalyzeController;
use ElSchneider\GreenwashingChecker\Http\Controllers\ApprovalController;
use ElSchneider\GreenwashingChecker\Http\Controllers\DismissController;
use Illuminate\Support\Facades\Route;

Route::post('greenwashing-checker/analyze', AnalyzeController::class)->name('greenwashing-checker.analyze');
Route::get('greenwashing-checker/jobs/{job}', JobStatusController::class)->defaults('scope', 'greenwashing-checker')->name('greenwashing-checker.jobs.show');
Route::post('greenwashing-checker/dismiss', DismissController::class)->name('greenwashing-checker.dismiss');

Route::match(['post', 'delete'], 'greenwashing-checker/approve', ApprovalController::class)->name('greenwashing-checker.approve');
