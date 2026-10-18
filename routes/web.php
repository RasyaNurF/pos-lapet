<?php

declare(strict_types=1);

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\TransactionController;
use App\Livewire\PosTerminal;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/', fn () => redirect()->route('dashboard'));

    // Shift: buka/tutup (wajib sebelum akses POS).
    Route::get('/shifts/open', [ShiftController::class, 'openForm'])->name('shifts.open.form');
    Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
    Route::get('/shifts/close', [ShiftController::class, 'closeForm'])->name('shifts.close.form');
    Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');

    // POS Terminal (wajib shift terbuka).
    Route::get('/pos', PosTerminal::class)
        ->middleware(['permission:pos.view', 'shift.open'])
        ->name('pos');

    // Dashboard.
    Route::get('/dashboard', DashboardController::class)
        ->middleware('permission:pos.view|products.view|reports.view')
        ->name('dashboard');

    // Master data.
    Route::middleware('permission:products.view')->group(function (): void {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    });
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete')->name('products.destroy');
    Route::put('/product-variants/{variant}/stock', [ProductController::class, 'updateVariantStock'])->middleware('permission:products.update')->name('products.variant.stock');

    Route::middleware('permission:categories.view')->group(function (): void {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    });
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('permission:categories.create')->name('categories.store');
    Route::put('/categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.update')->name('categories.update');
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.delete')->name('categories.destroy');

    Route::middleware('permission:customers.view')->group(function (): void {
        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    });
    Route::post('/customers', [CustomerController::class, 'store'])->middleware('permission:customers.create')->name('customers.store');
    Route::put('/customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update')->name('customers.update');
    Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete')->name('customers.destroy');

    // Transaksi.
    Route::get('/transactions', [TransactionController::class, 'index'])
        ->middleware('permission:transactions.view')
        ->name('transactions.index');
    Route::get('/transactions/{transaction}/receipt', [TransactionController::class, 'receipt'])
        ->middleware('permission:transactions.print')
        ->name('transactions.receipt');
    Route::post('/transactions/{transaction}/void', [TransactionController::class, 'void'])
        ->middleware('permission:pos.void')
        ->name('transactions.void');

    // Laporan & export.
    Route::get('/reports/sales', [ReportController::class, 'sales'])
        ->middleware('permission:reports.view')
        ->name('reports.sales');
    Route::get('/reports/stock', [ReportController::class, 'stock'])
        ->middleware('permission:reports.view')
        ->name('reports.stock');
    Route::get('/reports/export', [ReportController::class, 'export'])
        ->middleware('permission:reports.export')
        ->name('reports.export');
});
