<?php

use App\Http\Controllers\AccessRequestController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\BankDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DealController;
use App\Http\Controllers\DealDisputeController;
use App\Http\Controllers\DealInstructionChangeController;
use App\Http\Controllers\DealNotificationController;
use App\Http\Controllers\DealTimelineController;
use App\Http\Controllers\FeePaymentController;
use App\Http\Controllers\FundingDocumentController;
use App\Http\Controllers\FundingRequestController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OfferController;
use App\Http\Controllers\PaymentOperationsController;
use App\Http\Controllers\SelectionController;
use App\Http\Controllers\SettlementConfirmationController;
use App\Http\Controllers\SettlementInstructionController;
use App\Http\Resources\AccountResource;
use App\Http\Resources\BankResource;
use App\Models\Bank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');
Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
    Route::get('/forgot-password', [AuthController::class, 'forgotForm'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'forgot'])->middleware('throttle:password-reset')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'reset'])->middleware('throttle:password-reset')->name('password.update');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->middleware('throttle:invitations')->name('invitation.accept');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'active-account'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/operations', [BankController::class, 'index'])->name('operations');
    Route::get('/operations/disputes', [DealDisputeController::class, 'index'])->name('disputes.operations');
    Route::post('/operations/disputes/{dispute}/review', [DealDisputeController::class, 'review'])->name('disputes.review');
    Route::post('/operations/disputes/{dispute}/resolution', [DealDisputeController::class, 'resolve'])->name('disputes.resolve');
    Route::get('/operations/payments', [PaymentOperationsController::class, 'index'])->name('payments.operations');
    Route::post('/operations/payment-receipts/{receipt}/review', [PaymentOperationsController::class, 'review'])->name('payments.receipts.review');
    Route::post('/operations/payment-receipts/{receipt}/refund', [PaymentOperationsController::class, 'refund'])->name('payments.receipts.refund');
    Route::post('/banks', [BankController::class, 'store'])->name('banks.store');
    Route::get('/banks/{bank}', [BankController::class, 'show'])->name('banks.show');
    Route::patch('/banks/{bank}', [BankController::class, 'update'])->name('banks.update');
    Route::post('/banks/{bank}/verify', [BankController::class, 'verify'])->name('banks.verify');
    Route::post('/banks/{bank}/suspend', [BankController::class, 'suspend'])->name('banks.suspend');
    Route::post('/banks/{bank}/reopen', [BankController::class, 'reopen'])->name('banks.reopen');
    Route::post('/bank/access-requests', [AccessRequestController::class, 'store'])->name('access.store');
    Route::post('/bank/members/{membership}/roles', [AccessRequestController::class, 'roles'])->name('access.roles');
    Route::delete('/bank/members/{membership}', [AccessRequestController::class, 'revoke'])->name('access.revoke');
    Route::post('/access-requests/{accessRequest}/review', [AccessRequestController::class, 'review'])->name('access.review');
    Route::post('/banks/{bank}/documents', [BankDocumentController::class, 'store'])->name('documents.store');
    Route::get('/documents/{document}', [BankDocumentController::class, 'show'])->name('documents.show');
    Route::get('/marketplace', [FundingRequestController::class, 'marketplace'])->name('marketplace');
    Route::get('/api/v1/marketplace', [FundingRequestController::class, 'marketplace'])->name('api.marketplace');
    Route::get('/funding-requests/create', [FundingRequestController::class, 'create'])->name('funding.create');
    Route::get('/funding-requests/{fundingRequest}/edit', [FundingRequestController::class, 'edit'])->name('funding.edit');
    Route::post('/funding-requests/{fundingRequest}/documents', [FundingDocumentController::class, 'store'])->name('funding.documents.store');
    Route::get('/funding-documents/{document}', [FundingDocumentController::class, 'show'])->name('funding.documents.show');
    Route::delete('/funding-documents/{document}', [FundingDocumentController::class, 'destroy'])->name('funding.documents.destroy');
    Route::get('/funding-requests/{fundingRequest}/offer/create', [OfferController::class, 'create'])->name('offers.create');
    Route::get('/offers/{offer}/edit', [OfferController::class, 'edit'])->name('offers.edit');
    foreach (['' => '', 'api/v1/' => 'api.'] as $prefix => $name) {
        Route::prefix($prefix)->name($name)->group(function (): void {
            Route::get('/settlement-instructions', [SettlementInstructionController::class, 'index'])->name('instructions.index');
            Route::post('/settlement-instructions', [SettlementInstructionController::class, 'store'])->name('instructions.store');
            Route::get('/settlement-instructions/{instruction}', [SettlementInstructionController::class, 'show'])->name('instructions.show');
            Route::post('/settlement-instructions/{instruction}/review', [SettlementInstructionController::class, 'review'])->name('instructions.review');
            Route::get('/offers', [OfferController::class, 'index'])->name('offers.index');
            Route::post('/funding-requests/{fundingRequest}/offers', [OfferController::class, 'store'])->name('offers.store');
            Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
            Route::patch('/offers/{offer}', [OfferController::class, 'update'])->name('offers.update');
            Route::post('/offers/{offer}/submit', [OfferController::class, 'submit'])->name('offers.submit');
            Route::post('/offers/{offer}/approvals/{approval}/review', [OfferController::class, 'review'])->name('offers.review');
            Route::post('/offers/{offer}/withdrawal', [OfferController::class, 'withdraw'])->name('offers.withdraw');
            Route::get('/funding-requests/{fundingRequest}/offers', [SelectionController::class, 'index'])->name('funding.offers');
            Route::post('/funding-requests/{fundingRequest}/selection', [SelectionController::class, 'store'])->name('selection.store');
            Route::post('/funding-requests/{fundingRequest}/selection/{selection}/review', [SelectionController::class, 'review'])->name('selection.review');
            Route::get('/notifications', [DealNotificationController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/{notification}/read', [DealNotificationController::class, 'read'])->name('notifications.read');
            Route::get('/deals/{deal}/timeline', DealTimelineController::class)->name('deals.timeline');
            Route::post('/deals/{deal}/confirmations', [SettlementConfirmationController::class, 'store'])->name('confirmations.store');
            Route::post('/deals/{deal}/confirmations/{confirmation}/review', [SettlementConfirmationController::class, 'review'])->name('confirmations.review');
            Route::post('/deals/{deal}/disputes', [DealDisputeController::class, 'store'])->name('disputes.store');
            Route::get('/deals/{deal}', [DealController::class, 'show'])->name('deals.show');
            Route::get('/deals', [DealController::class, 'index'])->name('deals.index');
            Route::get('/deals/{deal}/settlement-instructions', [DealController::class, 'settlement'])->name('deals.settlement');
            Route::post('/deals/{deal}/fee-payments', [FeePaymentController::class, 'store'])->name('payments.store');
            Route::get('/fee-payments/{feePayment}', [FeePaymentController::class, 'show'])->name('payments.show');
            Route::post('/deals/{deal}/instruction-changes', [DealInstructionChangeController::class, 'store'])->name('deal-instructions.store');
            Route::post('/deals/{deal}/instruction-changes/{change}/review', [DealInstructionChangeController::class, 'review'])->name('deal-instructions.review');
        });
    }
    foreach (['funding-requests' => 'funding.', 'api/v1/funding-requests' => 'api.funding.'] as $prefix => $name) {
        Route::prefix($prefix)->name($name)->group(function (): void {
            Route::get('/', [FundingRequestController::class, 'index'])->name('index');
            Route::post('/', [FundingRequestController::class, 'store'])->name('store');
            Route::get('/{fundingRequest}', [FundingRequestController::class, 'show'])->name('show');
            Route::patch('/{fundingRequest}', [FundingRequestController::class, 'update'])->name('update');
            Route::post('/{fundingRequest}/submit', [FundingRequestController::class, 'submit'])->name('submit');
            Route::post('/{fundingRequest}/cancellation', [FundingRequestController::class, 'cancel'])->name('cancel');
            Route::post('/{fundingRequest}/approvals/{approval}/review', [FundingRequestController::class, 'review'])->name('review');
        });
    }

    Route::get('/api/v1/me', fn (Request $request) => new AccountResource($request->user()))->name('api.me');
    Route::get('/api/v1/banks/{bank}', function (Bank $bank): BankResource {
        Gate::authorize('view', $bank);

        return new BankResource($bank);
    })->name('api.banks.show');
});
