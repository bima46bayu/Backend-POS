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
use App\Http\Controllers\StoreLocationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StockReconciliationController as RC;

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

    Route::get('/store-locations',        [StoreLocationController::class, 'index']);
    Route::post('/store-locations',       [StoreLocationController::class, 'store']);
    Route::get('/store-locations/{id}',   [StoreLocationController::class, 'show'])->whereNumber('id');
    Route::put('/store-locations/{id}',   [StoreLocationController::class, 'update'])->whereNumber('id');
    Route::delete('/store-locations/{id}',[StoreLocationController::class, 'destroy'])->whereNumber('id');

    Route::get('/inventory/layers',       [InventoryController::class, 'layers']);       // ?product_id=&store_id=&per_page=
    Route::get('/inventory/consumptions', [InventoryController::class, 'consumptions']); // ?product_id=&sale_id=&per_page=
    Route::get('/inventory/valuation',    [InventoryController::class, 'valuation']);    // nilai persediaan (on-hand x cost)
    Route::get('/sales/{sale}/fifo-breakdown', [SaleController::class, 'fifoBreakdown'])->whereNumber('sale');

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
    // routes/api.php
    Route::get('/reports/sales-items', [ReportController::class, 'salesItems']);


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

        // SubCategories CRUD
        Route::prefix('sub-categories')->group(function () {
            Route::post('/',                    [SubCategoryController::class, 'store']);
            Route::put('/{subCategory}',        [SubCategoryController::class, 'update'])->whereNumber('subCategory');
            Route::delete('/{subCategory}',     [SubCategoryController::class, 'destroy'])->whereNumber('subCategory');
        });

        // Products CRUD + upload (hapus route upload duplikat)
        Route::prefix('products')->group(function () {
            Route::post('/',                    [ProductController::class, 'store']);
            Route::put('/{product}',            [ProductController::class, 'update'])->whereNumber('product');
            Route::patch('/{product}',          [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('/{product}',         [ProductController::class, 'destroy'])->whereNumber('product');
            Route::post('/{product}/upload',    [ProductController::class, 'upload'])->whereNumber('product');

            // Manual stock adjustment
            Route::post('/{product}/stock/change', [StockController::class,'change'])->whereNumber('product');
        });

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/products/import/template', [ProductImportController::class, 'template']);
            Route::post('/products/import', [ProductImportController::class, 'import']);
        });

        // Suppliers CRUD
        Route::prefix('suppliers')->group(function () {
            Route::post('/',                    [SupplierController::class, 'store']);
            Route::put('/{supplier}',           [SupplierController::class, 'update'])->whereNumber('supplier');
            Route::delete('/{supplier}',        [SupplierController::class, 'destroy'])->whereNumber('supplier');
        });

        // Sales — void
        Route::post('sales/{sale}/void',        [SaleController::class, 'void'])->whereNumber('sale');
        

        // Inventory (produk spesifik)
        Route::get('/inventory/products',                 [InventoryController::class, 'inventoryProducts']);
        Route::get('/inventory/products/{id}/logs',       [InventoryController::class, 'productLogs'])->whereNumber('id');
        Route::get('/inventory/products/{id}/summary',    [InventoryController::class, 'productSummary'])->whereNumber('id');

        // === Master User ===
        Route::prefix('users')->group(function () {
            Route::get('/',                  [UserController::class, 'index']);   // ?search=&role=&per_page=
            Route::get('/{user}',            [UserController::class, 'show'])->whereNumber('user');
            Route::post('/',                 [UserController::class, 'store']);
            Route::put('/{user}',            [UserController::class, 'update'])->whereNumber('user');
            Route::patch('/{user}',          [UserController::class, 'update'])->whereNumber('user');
            Route::delete('/{user}',         [UserController::class, 'destroy'])->whereNumber('user');

            // Aksi khusus
            Route::patch('/{user}/role',     [UserController::class, 'updateRole'])->whereNumber('user');
            Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user');

            // Dropdown helper
            Route::get('/roles/options',     [UserController::class, 'roleOptions']);
        });

        // ✅ TARUH DULUAN
        Route::get('/stock-reconciliation/template', [RC::class, 'template'])
            ->name('stock-reconciliation.template');
        Route::get   ('/stock-reconciliation',              [RC::class, 'index']);
        Route::post  ('/stock-reconciliation',              [RC::class, 'store']);
        Route::get   ('/stock-reconciliation/{id}',         [RC::class, 'show'])->whereNumber('id');
        Route::post  ('/stock-reconciliation/{id}/upload',  [RC::class, 'upload'])->whereNumber('id');
        Route::post  ('/stock-reconciliation/{id}/apply',   [RC::class, 'apply'])->whereNumber('id');
        Route::delete('/stock-reconciliation/{id}',         [RC::class, 'destroy'])->whereNumber('id');
        // routes/api.php
    });
});
