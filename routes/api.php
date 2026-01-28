<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdditionalChargeController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\PaymentRequestItemController;
use App\Http\Controllers\PaymentRequestBalanceController;
use App\Http\Controllers\PosCheckoutController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReceiveController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\StoreLocationController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| CORS Preflight
|--------------------------------------------------------------------------
*/
Route::options('{any}', fn () => response()->noContent())->where('any', '.*');

/*
|--------------------------------------------------------------------------
| PUBLIC
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::prefix('store-locations')->group(function () {
    Route::get('/{id}/logo', [StoreLocationController::class, 'logo'])->whereNumber('id');
});

Route::get('/payment-requests/{id}/pdf', [PaymentRequestController::class, 'pdf'])
    ->name('payment.pdf')
    ->middleware('signed');
Route::get('/payment-requests/{id}/pdf-link', [PaymentRequestController::class, 'getPdfLink']);

/*
|--------------------------------------------------------------------------
| AUTHENTICATED
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'daily.session'])->group(function () {

    /*
    | Auth
    */
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/store', [AuthController::class, 'updateStore']);

    /*
    | Store Locations
    */
    Route::prefix('store-locations')->group(function () {
        Route::get('/', [StoreLocationController::class, 'index']);
        Route::post('/', [StoreLocationController::class, 'store']);
        Route::get('/{id}', [StoreLocationController::class, 'show'])->whereNumber('id');
        Route::put('/{id}', [StoreLocationController::class, 'update'])->whereNumber('id');
        Route::delete('/{id}', [StoreLocationController::class, 'destroy'])->whereNumber('id');
        Route::post('/{id}/logo', [StoreLocationController::class, 'uploadLogo'])->whereNumber('id');
    });

    /*
    | Inventory
    */
    Route::prefix('inventory')->group(function () {
        Route::get('/layers', [InventoryController::class, 'layers']);
        Route::get('/consumptions', [InventoryController::class, 'consumptions']);
        Route::get('/valuation', [InventoryController::class, 'valuation']);

        Route::get('/products', [InventoryController::class, 'inventoryProducts']);
        Route::get('/products/{id}/logs', [InventoryController::class, 'productLogs'])->whereNumber('id');
        Route::get('/products/{id}/summary', [InventoryController::class, 'productSummary'])->whereNumber('id');
        Route::get('/products/summary', [InventoryController::class, 'productSummaryBatch']);
    });

    /*
    | Categories
    */
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{category}', [CategoryController::class, 'show'])->whereNumber('category');
    });

    /*
    | Sub Categories
    */
    Route::prefix('sub-categories')->group(function () {
        Route::get('/', [SubCategoryController::class, 'index']);
        Route::get('/{subCategory}', [SubCategoryController::class, 'show'])->whereNumber('subCategory');
    });

    /*
    | Products
    */
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/search', [ProductController::class, 'search']);
        Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product');
    });

    /*
    | Suppliers
    */
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index']);
        Route::get('/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier');
    });

    /*
    | Stock Logs
    */
    Route::prefix('stock-logs')->group(function () {
        Route::get('/', [StockLogController::class, 'index']);
        Route::get('/{stock_log}', [StockLogController::class, 'show'])->whereNumber('stock_log');
    });

    /*
    | Sales
    */
    Route::prefix('sales')->group(function () {
        Route::get('/', [SaleController::class, 'index']);
        Route::get('/{sale}', [SaleController::class, 'show'])->whereNumber('sale');
        Route::post('/', [SaleController::class, 'store']);
        Route::get('/{sale}/fifo-breakdown', [SaleController::class, 'fifoBreakdown'])->whereNumber('sale');
    });

    /*
    | Reports
    */
    Route::prefix('reports')->group(function () {
        Route::get('/sales-items', [ReportController::class, 'salesItems']);
    });

    /*
    | POS
    */
    Route::prefix('pos')->group(function () {
        Route::post('/checkout', [PosCheckoutController::class, 'checkout']);
    });

    /*
    | Discounts
    */
    Route::prefix('discounts')->group(function () {
        Route::get('/', [DiscountController::class, 'index']);
        Route::get('/{discount}', [DiscountController::class, 'show'])->whereNumber('discount');
    });

    /*
    | Additional Charges
    */
    Route::apiResource('additional-charges', AdditionalChargeController::class);

    /*
    |--------------------------------------------------------------------------
    | STAFF (admin + kasir)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin,kasir')->group(function () {

        Route::prefix('purchases')->group(function () {
            Route::get('/', [PurchaseController::class, 'index']);
            Route::get('/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::post('/', [PurchaseController::class, 'store']);
            Route::get('/{purchase}/for-receipt', [PurchaseReceiveController::class, 'forReceipt'])->whereNumber('purchase');
        });

        Route::prefix('receipts')->group(function () {
            Route::get('/', [GoodsReceiptController::class, 'index']);
            Route::get('/{goodsReceipt}', [GoodsReceiptController::class, 'show'])->whereNumber('goodsReceipt');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin')->group(function () {

        Route::prefix('purchases')->group(function () {
            Route::post('/{purchase}/approve', [PurchaseController::class, 'approve'])->whereNumber('purchase');
            Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase');
            Route::post('/{purchase}/receive', [PurchaseReceiveController::class, 'receive'])->whereNumber('purchase');
        });

        Route::prefix('categories')->group(function () {
            Route::post('/', [CategoryController::class, 'store']);
            Route::match(['put','patch'], '/{category}', [CategoryController::class, 'update'])->whereNumber('category');
            Route::delete('/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
        });

        Route::prefix('sub-categories')->group(function () {
            Route::post('/', [SubCategoryController::class, 'store']);
            Route::put('/{sub_category}', [SubCategoryController::class, 'update'])->whereNumber('sub_category');
            Route::delete('/{sub_category}', [SubCategoryController::class, 'destroy'])->whereNumber('sub_category');
        });

        Route::prefix('products')->group(function () {
            Route::post('/', [ProductController::class, 'store']);
            Route::match(['put','patch'], '/{product}', [ProductController::class, 'update'])->whereNumber('product');
            Route::delete('/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
            Route::post('/{product}/upload', [ProductController::class, 'upload'])->whereNumber('product');
            Route::post('/{product}/stock/change', [StockController::class, 'change'])->whereNumber('product');
        });

        Route::prefix('products')->group(function () {
            Route::get('/import/template', [ProductImportController::class, 'template']);
            Route::post('/import', [ProductImportController::class, 'import']);
        });

        Route::prefix('suppliers')->group(function () {
            Route::post('/', [SupplierController::class, 'store']);
            Route::put('/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier');
            Route::delete('/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier');
        });

        Route::prefix('sales')->group(function () {
            Route::post('/{sale}/void', [SaleController::class, 'void'])->whereNumber('sale');
        });

        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/{user}', [UserController::class, 'show'])->whereNumber('user');
            Route::post('/', [UserController::class, 'store']);
            Route::match(['put','patch'], '/{user}', [UserController::class, 'update'])->whereNumber('user');
            Route::delete('/{user}', [UserController::class, 'destroy'])->whereNumber('user');
            Route::patch('/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user');
            Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user');
            Route::get('/roles/options', [UserController::class, 'roleOptions']);
        });

        Route::prefix('stock-reconciliation')->group(function () {
            Route::get('/', [StockReconciliationController::class, 'index']);
            Route::post('/', [StockReconciliationController::class, 'store']);
            Route::get('/{id}', [StockReconciliationController::class, 'show']);
            Route::patch('/{id}/items', [StockReconciliationController::class, 'bulkUpdateItems']);
            Route::get('/{id}/template', [StockReconciliationController::class, 'template']);
            Route::post('/{id}/upload', [StockReconciliationController::class, 'upload']);
            Route::post('/{id}/apply', [StockReconciliationController::class, 'apply']);
            Route::delete('/{id}', [StockReconciliationController::class, 'destroy']);
        });

        Route::apiResource('units', UnitController::class)->only(['index','store','update','destroy']);

        Route::prefix('discounts')->group(function () {
            Route::post('/', [DiscountController::class, 'store']);
            Route::put('/{discount}', [DiscountController::class, 'update']);
            Route::delete('/{discount}', [DiscountController::class, 'destroy']);
            Route::patch('/{discount}/toggle', [DiscountController::class, 'toggle']);
        });

        Route::apiResource('bank-accounts', BankAccountController::class);
        Route::apiResource('coas', CoaController::class);
        Route::apiResource('payees', PayeeController::class);

        Route::prefix('payment-requests')->group(function () {
            Route::get('/', [PaymentRequestController::class, 'index']);
            Route::post('/', [PaymentRequestController::class, 'store']);
            Route::get('/{id}', [PaymentRequestController::class, 'show']);
            Route::delete('/{id}', [PaymentRequestController::class, 'destroy']);

            Route::post('/{id}/items', [PaymentRequestItemController::class, 'store']);
            Route::put('/{id}/items/{item}', [PaymentRequestItemController::class, 'update']);
            Route::delete('/{id}/items/{item}', [PaymentRequestItemController::class, 'destroy']);

            Route::post('/{id}/balances', [PaymentRequestBalanceController::class, 'store']);
            Route::put('/{id}/balances/{balance}', [PaymentRequestBalanceController::class, 'update']);
            Route::delete('/{id}/balances/{balance}', [PaymentRequestBalanceController::class, 'destroy']);
        });

    });

});
