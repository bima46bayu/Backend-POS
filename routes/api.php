<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;
use App\Http\Controllers\SaleController;

// ------- Auth (tanpa login) -------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// ------- butuh login -------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // =========================
    //  KASIR & ADMIN (read-only + transaksi)
    // =========================

    // READ-ONLY Categories & Products (index, show)
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);

    Route::get('products', [ProductController::class, 'index']);   // termasuk search & filter via query string
    Route::get('products/{product}', [ProductController::class, 'show']);

    // Stock Logs (riwayat) — hanya GET
    Route::get('stock-logs', [StockLogController::class, 'index']);
    Route::get('stock-logs/{stock_log}', [StockLogController::class, 'show']);

    // Sales (transaksi POS) — kasir & admin boleh
    Route::get('sales', [SaleController::class, 'index']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::post('sales', [SaleController::class, 'store']);

    // Search products (Scanner)
    // taruh statis dulu
    Route::get('products/search', [ProductController::class, 'search']);
    // baru yang dinamis
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');


    // =========================
    //  ADMIN ONLY (semua mutasi data)
    // =========================
    Route::middleware('auth:sanctum','role:admin')->group(function () {
        // Categories CRUD selain index/show (sudah didefinisikan di atas)
        Route::post('categories', [CategoryController::class, 'store']);
        Route::put('categories/{category}', [CategoryController::class, 'update']);
        Route::patch('categories/{category}', [CategoryController::class, 'update']);
        Route::delete('categories/{category}', [CategoryController::class, 'destroy']);

        // Products CRUD selain index/show
        Route::post('products', [ProductController::class, 'store']);
        Route::put('products/{product}', [ProductController::class, 'update']);
        Route::patch('products/{product}', [ProductController::class, 'update']);
        Route::delete('products/{product}', [ProductController::class, 'destroy']);
        Route::post('products/{product}/upload', [ProductController::class,'upload']);

        // Stock adjustment manual (di luar transaksi sale)
        Route::post('products/{product}/stock/change', [StockController::class,'change']);

        // Void sale (kembalikan stok)
        Route::post('sales/{sale}/void', [SaleController::class, 'void']);
    });
});
