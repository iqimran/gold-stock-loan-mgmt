<?php

use App\Http\Controllers\Api\V1\CurrentUserController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\Api\V1\UserController;
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
        Route::get('customers/{customer}/image', [CustomerController::class, 'image'])->name('customers.image');
    });
});
