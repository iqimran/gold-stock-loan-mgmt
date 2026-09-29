<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerImageController;
use App\Http\Controllers\Loans\LoanCollateralController;
use App\Http\Controllers\Loans\LoanController;
use App\Http\Controllers\Payments\PaymentController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    // Customers: DELETE archives; customers are never hard-deleted.
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
    Route::get('customers/{customer}/image', CustomerImageController::class)->name('customers.image');

    // Loan detail and its actions (no delete: loans are closed or cancelled).
    Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
    Route::post('loans/{loan}/activate', [LoanController::class, 'activate'])->name('loans.activate');
    Route::post('loans/{loan}/close', [LoanController::class, 'close'])->name('loans.close');
    Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->name('loans.cancel');

    // Collateral from the loan screen (no delete: items are released, never deleted).
    Route::post('loans/{loan}/collateral', [LoanCollateralController::class, 'store'])->name('loans.collateral.store');
    Route::patch('collateral/{collateralItem}', [LoanCollateralController::class, 'update'])->name('collateral.update');
    Route::post('collateral/{collateralItem}/release', [LoanCollateralController::class, 'release'])->name('collateral.release');

    // Payments: no edit or delete; a posted payment is only ever reversed.
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::resource('roles', RoleController::class)->except(['show']);
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
