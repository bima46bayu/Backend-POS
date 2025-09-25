<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReceiveController;
use App\Http\Controllers\GoodsReceiptController;

/*
|--------------------------------------------------------------------------
| PUBLIC (tanpa login)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED (semua user login)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me',          [AuthController::class, 'me']);
    Route::put('/me/store',    [AuthController::class, 'updateStore']);

    // ---------- READ-ONLY umum ----------
    Route::prefix('categories')->group(function () {
        Route::get('/',                 [CategoryController::class, 'index']);
        Route::get('/{category}',       [CategoryController::class, 'show'])->whereNumber('category');
    });

    Route::prefix('sub-categories')->group(function () {
        Route::get('/',                 [SubCategoryController::class, 'index']);
        Route::get('/{subCategory}',    [SubCategoryController::class, 'show'])->whereNumber('subCategory');
    });

    Route::prefix('products')->group(function () {
        Route::get('/',                 [ProductController::class, 'index']); // ?search=&sku=&category_id=
        Route::get('/search',           [ProductController::class, 'search']); // scan by SKU
        Route::get('/{product}',        [ProductController::class, 'show'])->whereNumber('product');
    });

    Route::prefix('suppliers')->group(function () {
        Route::get('/',                 [SupplierController::class, 'index']);
        Route::get('/{supplier}',       [SupplierController::class, 'show'])->whereNumber('supplier');
    });

    Route::prefix('stock-logs')->group(function () {
        Route::get('/',                 [StockLogController::class, 'index']);
        Route::get('/{stock_log}',      [StockLogController::class, 'show'])->whereNumber('stock_log');
    });

    Route::prefix('sales')->group(function () {
        Route::get('/',                 [SaleController::class, 'index']);
        Route::get('/{sale}',           [SaleController::class, 'show'])->whereNumber('sale');
        Route::post('/',                [SaleController::class, 'store']); // transaksi POS
    });

    // ---------- STAFF (admin + kasir) ----------
    Route::middleware('role:admin,kasir')->group(function () {
        // Purchase (buat draft, lihat, preload GR)
        Route::prefix('purchases')->group(function () {
            Route::get('/',                     [PurchaseController::class, 'index']); // ?status=&supplier_id=&from=&to=
            Route::get('/{purchase}',           [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::post('/',                    [PurchaseController::class, 'store']); // create PO (draft)

            // GR inline (form Receive)
            Route::get('/{purchase}/for-receipt', [PurchaseReceiveController::class, 'forReceipt'])
                ->whereNumber('purchase');
        });

        // (opsional) GR list/detail
        Route::prefix('receipts')->group(function () {
            Route::get('/',                   [GoodsReceiptController::class, 'index']);
            Route::get('/{goodsReceipt}',     [GoodsReceiptController::class, 'show'])->whereNumber('goodsReceipt');
        });
    });

    // ---------- ADMIN ONLY ----------
    Route::middleware('role:admin')->group(function () {
        // Purchase: approve/cancel & post receive (ubah stok)
        Route::prefix('purchases')->group(function () {
            Route::post('/{purchase}/approve', [PurchaseController::class, 'approve'])->whereNumber('purchase');
            Route::post('/{purchase}/cancel',  [PurchaseController::class, 'cancel'])->whereNumber('purchase');
            Route::post('/{purchase}/receive', [PurchaseReceiveController::class, 'receive'])->whereNumber('purchase');
        });

        // Categories CRUD (PUT & PATCH dipisah)
        Route::prefix('categories')->group(function () {
            Route::post('/',                    [CategoryController::class, 'store']);
            Route::put('/{category}',           [CategoryController::class, 'update'])->whereNumber('category');
            Route::patch('/{category}',         [CategoryController::class, 'update'])->whereNumber('category');
            Route::delete('/{category}',        [CategoryController::class, 'destroy'])->whereNumber('category');
        });

        // SubCategories CRUD (tetap seperti semula: hanya PUT)
        Route::prefix('sub-categories')->group(function () {
            Route::post('/',                    [SubCategoryController::class, 'store']);
            Route::put('/{subCategory}',        [SubCategoryController::class, 'update'])->whereNumber('subCategory');
            Route::delete('/{subCategory}',     [SubCategoryController::class, 'destroy'])->whereNumber('subCategory');
        });

        // Products CRUD + upload (PUT & PATCH dipisah)
        Route::prefix('products')->group(function () {
            Route::post('/',                    [ProductController::class, 'store']);
            Route::put('/{product}',            [ProductController::class, 'update'])->whereNumber('product');
            Route::patch('/{product}',          [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('/{product}',         [ProductController::class, 'destroy'])->whereNumber('product');
            Route::post('/{product}/upload',    [ProductController::class, 'upload'])->whereNumber('product');
            Route::post('/products/{product}/upload', [ProductController::class, 'upload']);


            // Manual stock adjustment
            Route::post('/{product}/stock/change', [StockController::class,'change'])->whereNumber('product');
        });

        // Suppliers CRUD (tetap: PUT saja)
        Route::prefix('suppliers')->group(function () {
            Route::post('/',                    [SupplierController::class, 'store']);
            Route::put('/{supplier}',           [SupplierController::class, 'update'])->whereNumber('supplier');
            Route::delete('/{supplier}',        [SupplierController::class, 'destroy'])->whereNumber('supplier');
        });

        // Sales — void
        Route::post('sales/{sale}/void',        [SaleController::class, 'void'])->whereNumber('sale');
    });
});
