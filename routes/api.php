<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;
use App\Http\Controllers\SaleController;

// NEW
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\PurchaseController;

// ------- Auth (public) -------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

// ------- Auth required -------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // =========================
    //  KASIR & ADMIN (read-only + transaksi)
    // =========================

    // Categories (read-only)
    Route::get('categories',              [CategoryController::class, 'index']);
    Route::get('categories/{category}',   [CategoryController::class, 'show'])->whereNumber('category');

    // Products (read-only + search & scanner)
    Route::get('products',                [ProductController::class, 'index']); // ?search=...&sku=...&category_id=...
    Route::get('products/search',         [ProductController::class, 'search']); // scanner by SKU (statis duluan!)
    Route::get('products/{product}',      [ProductController::class, 'show'])->whereNumber('product'); // dinamis setelah /search

    // Stock Logs (riwayat)
    Route::get('stock-logs',              [StockLogController::class, 'index']);
    Route::get('stock-logs/{stock_log}',  [StockLogController::class, 'show'])->whereNumber('stock_log');

    // Sales (transaksi POS)
    Route::get('sales',                   [SaleController::class, 'index']);
    Route::get('sales/{sale}',            [SaleController::class, 'show'])->whereNumber('sale');
    Route::post('sales',                  [SaleController::class, 'store']);

    // ===== NEW: Supplier (read untuk kasir/admin) =====
    Route::get('suppliers',               [SupplierController::class, 'index']);
    Route::get('suppliers/{supplier}',    [SupplierController::class, 'show'])->whereNumber('supplier');

    // ===== NEW: SubCategory (read untuk kasir/admin) =====
    Route::get('sub-categories',                [SubCategoryController::class, 'index']);
    Route::get('sub-categories/{subCategory}',  [SubCategoryController::class, 'show'])->whereNumber('subCategory');

    // ===== NEW: Purchase (draft/approval flow) =====
    Route::get('purchases',               [PurchaseController::class, 'index']); // ?status=pending|approved|rejected
    Route::get('purchases/{purchase}',    [PurchaseController::class, 'show'])->whereNumber('purchase');
    // Buat draft (pending) — kasir & admin
    Route::post('purchases',              [PurchaseController::class, 'store'])->middleware('role:admin,kasir');
    // Approve/Reject (GR) — admin only
    Route::post('purchases/{purchase}/approve', [PurchaseController::class, 'approve'])->middleware('role:admin')->whereNumber('purchase');
    Route::post('purchases/{purchase}/reject',  [PurchaseController::class, 'reject'])->middleware('role:admin')->whereNumber('purchase');

    // =========================
    //  ADMIN ONLY (mutasi data)
    // =========================
    Route::middleware('role:admin')->group(function () {
        // Categories CRUD
        Route::post('categories',                  [CategoryController::class, 'store']);
        Route::put('categories/{category}',        [CategoryController::class, 'update'])->whereNumber('category');
        Route::patch('categories/{category}',      [CategoryController::class, 'update'])->whereNumber('category');
        Route::delete('categories/{category}',     [CategoryController::class, 'destroy'])->whereNumber('category');

        // SubCategories CRUD (NEW)
        Route::post('sub-categories',                   [SubCategoryController::class, 'store']);
        Route::put('sub-categories/{subCategory}',      [SubCategoryController::class, 'update'])->whereNumber('subCategory');
        Route::delete('sub-categories/{subCategory}',   [SubCategoryController::class, 'destroy'])->whereNumber('subCategory');

        // Products CRUD
        Route::post('products',                    [ProductController::class, 'store']);
        Route::put('products/{product}',           [ProductController::class, 'update'])->whereNumber('product');
        Route::patch('products/{product}',         [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('products/{product}',        [ProductController::class, 'destroy'])->whereNumber('product');
        Route::post('products/{product}/upload',   [ProductController::class, 'upload'])->whereNumber('product');

        // Stock adjustment manual (opsional, di luar sales/purchase)
        Route::post('products/{product}/stock/change', [StockController::class,'change'])->whereNumber('product');

        // Suppliers CRUD (admin only)
        Route::post('suppliers',                 [SupplierController::class, 'store']);
        Route::put('suppliers/{supplier}',       [SupplierController::class, 'update'])->whereNumber('supplier');
        Route::delete('suppliers/{supplier}',    [SupplierController::class, 'destroy'])->whereNumber('supplier');

        // Sales — void
        Route::post('sales/{sale}/void',         [SaleController::class, 'void'])->whereNumber('sale');
    });
});

