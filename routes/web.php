<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Customers\CustomerController;
use App\Http\Controllers\Customers\CustomerImageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\Loans\LoanCollateralController;
use App\Http\Controllers\Loans\LoanController;
use App\Http\Controllers\Payments\PaymentController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    // Every signed-in user; each block is permission-aware (DashboardController).
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // PDF/Excel exports (reports.export + the data's own view permission). Before the {model} routes.
    Route::get('reports/{report}/export', [ExportController::class, 'report'])->middleware('throttle:exports')->name('reports.export');
    Route::get('loans/export', [ExportController::class, 'loans'])->middleware('throttle:exports')->name('loans.export');
    Route::get('payments/export', [ExportController::class, 'payments'])->middleware('throttle:exports')->name('payments.export');
    Route::get('customers/export', [ExportController::class, 'customers'])->middleware('throttle:exports')->name('customers.export');

    // Customers: DELETE archives; customers are never hard-deleted.
    Route::resource('customers', CustomerController::class);
    Route::post('customers/{customer}/restore', [CustomerController::class, 'restore'])->name('customers.restore');
    Route::get('customers/{customer}/image', CustomerImageController::class)->name('customers.image');

    // Loans: list, detail and actions (no delete: loans are closed or cancelled).
    Route::get('loans', [LoanController::class, 'index'])->name('loans.index');
    Route::get('loans/create', [LoanController::class, 'create'])->name('loans.create');
    Route::post('loans', [LoanController::class, 'store'])->name('loans.store');
    Route::get('loans/{loan}', [LoanController::class, 'show'])->name('loans.show');
    Route::get('loans/{loan}/edit', [LoanController::class, 'edit'])->name('loans.edit');
    Route::patch('loans/{loan}', [LoanController::class, 'update'])->name('loans.update');
    Route::post('loans/{loan}/activate', [LoanController::class, 'activate'])->middleware('throttle:financial')->name('loans.activate');
    Route::post('loans/{loan}/close', [LoanController::class, 'close'])->middleware('throttle:financial')->name('loans.close');
    Route::post('loans/{loan}/cancel', [LoanController::class, 'cancel'])->middleware('throttle:financial')->name('loans.cancel');

    // Collateral from the loan screen (no delete: items are released, never deleted).
    Route::post('loans/{loan}/collateral', [LoanCollateralController::class, 'store'])->name('loans.collateral.store');
    Route::patch('collateral/{collateralItem}', [LoanCollateralController::class, 'update'])->name('collateral.update');
    Route::post('collateral/{collateralItem}/release', [LoanCollateralController::class, 'release'])->middleware('throttle:financial')->name('collateral.release');

    // Payments: no edit or delete; a posted payment is only ever reversed.
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::get('payments/create', [PaymentController::class, 'create'])->name('payments.create');
    Route::post('payments', [PaymentController::class, 'store'])->middleware('throttle:financial')->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');
    Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->middleware('throttle:financial')->name('payments.reverse');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except(['show', 'destroy']);
        Route::patch('users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::resource('roles', RoleController::class)->except(['show']);

        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    });
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
