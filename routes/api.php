<?php

use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\CollateralController;
use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\InterestPeriodController;
use App\Http\Controllers\Api\V1\LoanController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Customers\CustomerImageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::post('auth/token', [TokenController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('auth.token.store');

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::delete('auth/token', [TokenController::class, 'destroy'])->name('auth.token.destroy');
        Route::get('user', CurrentUserController::class)->name('user');
        Route::get('users', [UserController::class, 'index'])->name('users.index');

        // Customers (docs/04-api.md). DELETE archives; customers are never hard-deleted.
        Route::apiResource('customers', CustomerController::class);
        Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
        Route::get('customers/{customer}/image', CustomerImageController::class)->name('customers.image');
        Route::get('customers/{customer}/loans', [LoanController::class, 'forCustomer'])->name('customers.loans');

        // Loans (docs/04-api.md). No DELETE: loans are cancelled or closed, never deleted.
        Route::apiResource('loans', LoanController::class)->except('destroy');
        Route::post('loans/{loan}/activate', [LoanController::class, 'activate'])->name('loans.activate');
        Route::post('loans/{loan}/close', [LoanController::class, 'close'])->name('loans.close');
        Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');

        // Collateral (docs/04-api.md). No DELETE: items are released, never deleted.
        Route::get('loans/{loan}/collateral', [CollateralController::class, 'forLoan'])->name('loans.collateral.index');
        Route::post('loans/{loan}/collateral', [CollateralController::class, 'store'])->name('loans.collateral.store');
        Route::get('collateral', [CollateralController::class, 'index'])->name('collateral.index');
        Route::get('collateral/{collateralItem}', [CollateralController::class, 'show'])->name('collateral.show');
        Route::match(['put', 'patch'], 'collateral/{collateralItem}', [CollateralController::class, 'update'])->name('collateral.update');
        Route::post('collateral/{collateralItem}/release', [CollateralController::class, 'release'])->name('collateral.release');

        // Payments (docs/04-api.md). No update/delete: a posted payment is only ever reversed.
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');
        Route::get('loans/{loan}/payments', [PaymentController::class, 'forLoan'])->name('loans.payments');
        Route::get('customers/{customer}/payments', [PaymentController::class, 'forCustomer'])->name('customers.payments');

        // Missed-interest alerts and due/overdue views (read-only).
        Route::get('alerts', [AlertController::class, 'index'])->name('alerts.index');
        Route::get('dashboard/missed-payment-alerts', [AlertController::class, 'summary'])->name('dashboard.missed-payment-alerts');
        Route::get('interest-periods', [InterestPeriodController::class, 'index'])->name('interest-periods.index');
        Route::get('loans/{loan}/interest-periods', [InterestPeriodController::class, 'forLoan'])->name('loans.interest-periods');
    });
});
