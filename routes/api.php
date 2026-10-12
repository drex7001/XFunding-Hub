<?php

use App\Http\Controllers\LoanApplicationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/loan-applications')->group(function () {
    Route::post('/', [LoanApplicationController::class, 'store']);
    Route::get('/{application}', [LoanApplicationController::class, 'show'])->name('loan-applications.show');
    Route::patch('/{application}/status', [LoanApplicationController::class, 'updateStatus']);
});
