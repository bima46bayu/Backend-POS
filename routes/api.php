<?php

use App\Http\Controllers\AdditionalChargeController;
use App\Http\Controllers\AppSettingController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BankAccountController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CoaController;
use App\Http\Controllers\DiscountController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoyaltyRewardController;
use App\Http\Controllers\Member;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\Staff;
use App\Http\Controllers\PayeeController;
use App\Http\Controllers\PaymentRequestBalanceController;
use App\Http\Controllers\PaymentRequestController;
use App\Http\Controllers\PaymentRequestItemController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProductOptionGroupController;
use App\Http\Controllers\ProductRecipeController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReceiveController;
use App\Http\Controllers\RegisterSessionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;
use App\Http\Controllers\StockReconciliationController;
use App\Http\Controllers\StockWriteOffController;
use App\Http\Controllers\StoreLocationController;
use App\Http\Controllers\SubCategoryController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ActivityLogController;
use Illuminate\Support\Facades\Route;

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
Route::post('/login', [AuthController::class, 'login']);
/*
| Member mobile API (Member-Mobile app). Single canonical surface: /api/v1/member.
| One path per action — no aliases. Member-Mobile pins this exact contract.
*/
Route::prefix('v1/member')->group(function () {
    Route::post('/auth/otp', [Member\AuthController::class, 'requestRegistrationOtp'])->middleware('throttle:5,1');
    Route::post('/auth/verify', [Member\AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('/auth/login', [Member\AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/auth/password/reset', [Member\AuthController::class, 'resetPassword'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'principal.member'])->group(function () {
        Route::post('/auth/logout', [Member\AuthController::class, 'logout']);

        Route::get('/profile', [Member\ProfileController::class, 'show']);
        Route::patch('/profile', [Member\ProfileController::class, 'update']);
        Route::get('/bootstrap', [Member\ProfileController::class, 'bootstrap']);
        Route::get('/activity', [Member\ProfileController::class, 'activity']);
        Route::get('/perks', [Member\ProfileController::class, 'perks']);
        Route::get('/promotions', [Member\ProfileController::class, 'promotions']);

        Route::get('/reward-categories', [Member\RewardController::class, 'categories']);
        Route::get('/rewards', [Member\RewardController::class, 'index']);
        Route::get('/rewards/{reward}', [Member\RewardController::class, 'show'])->whereNumber('reward');

        Route::get('/store-locations', [Member\ReservationController::class, 'stores']);
        Route::get('/reservations', [Member\ReservationController::class, 'index']);
        Route::post('/reservations', [Member\ReservationController::class, 'store']);
        Route::get('/reservations/{reservation}', [Member\ReservationController::class, 'show']);
        Route::post('/reservations/{reservation}/cancel', [Member\ReservationController::class, 'cancel']);
        Route::post('/reservations/{reservation}/qr', [Member\ReservationController::class, 'qr']);

        Route::get('/card', [Member\CardController::class, 'show']);
        Route::post('/card/qr', [Member\CardController::class, 'qr']);
    });
});
/*
 | Reachability probe for the mobile POS offline mode.
 | Intentionally public and DB-free: the client only needs to know whether the
 | API host answers, not whether its own token is still valid.
 */
Route::get('/ping', fn () => response()->json([
    'ok' => true,
    'time' => now()->toIso8601String(),
]));

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
Route::middleware(['auth:sanctum', 'principal.staff', 'daily.session'])->group(function () {
    /*
    | Staff-side reward pickup (POS-Mobile). Canonical and only surface.
    | `resolve` matches a pickup code the member reads out; `resolve-qr` matches
    | the short-lived token from the member app's QR.
    */
    Route::prefix('v1/staff/reward-redemptions')->group(function () {
        Route::get('/', [Staff\RewardReservationController::class, 'index']);
        Route::get('/resolve', [Staff\RewardReservationController::class, 'resolvePickupCode']);
        Route::post('/resolve-qr', [Staff\RewardReservationController::class, 'resolveQr']);
        Route::post('/{reservation}/fulfill', [Staff\RewardReservationController::class, 'fulfill']);
        Route::post('/{reservation}/reject', [Staff\RewardReservationController::class, 'reject']);
    });
    Route::post('/v1/staff/member-cards/resolve', [Staff\MemberCardController::class, 'resolve']);

    /*
    | Auth
    */
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::put('/me/store', [AuthController::class, 'updateStore']);

    Route::middleware('role:admin')->get('/activity-logs', [ActivityLogController::class, 'index']);

    /*
    | Store Locations
    */
    Route::prefix('store-locations')->group(function () {
        Route::get('/', [StoreLocationController::class, 'index']);
        Route::get('/{id}', [StoreLocationController::class, 'show'])->whereNumber('id');

        Route::middleware('role:admin|regional_manager|store_admin')->group(function () {
            Route::post('/', [StoreLocationController::class, 'store']);
            Route::put('/{id}', [StoreLocationController::class, 'update'])->whereNumber('id');
            Route::delete('/{id}', [StoreLocationController::class, 'destroy'])->whereNumber('id');
            Route::post('/{id}/logo', [StoreLocationController::class, 'uploadLogo'])->whereNumber('id');
        });
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
    Route::get('/reports/subcategory-month', [SubCategoryController::class, 'reportMonthly']);

    /*
    | Products
    */
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index']);
        Route::get('/search', [ProductController::class, 'search']);
        Route::get('/next-sku', [ProductController::class, 'nextSku']);
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
    | Stock write-offs (waste / spoiled / expired) — consumes FIFO layers
    */
    Route::get('units', [UnitController::class, 'index']);

    Route::prefix('stock-write-offs')->group(function () {
        Route::get('/reasons', [StockWriteOffController::class, 'reasons']);
        Route::get('/summary', [StockWriteOffController::class, 'summary']);
        Route::get('/batches', [StockWriteOffController::class, 'batches']);
        Route::put('/batches/{batch_uid}', [StockWriteOffController::class, 'updateBatch']);
        Route::post('/batches/{batch_uid}/submit', [StockWriteOffController::class, 'submitBatch']);
        Route::delete('/batches/{batch_uid}', [StockWriteOffController::class, 'destroyBatch']);
        Route::get('/', [StockWriteOffController::class, 'index']);
        Route::post('/', [StockWriteOffController::class, 'store']);
        Route::put('/{stock_write_off}', [StockWriteOffController::class, 'update'])
            ->whereNumber('stock_write_off');
        Route::post('/{stock_write_off}/submit', [StockWriteOffController::class, 'submit'])
            ->whereNumber('stock_write_off');
        Route::delete('/{stock_write_off}', [StockWriteOffController::class, 'destroy'])
            ->whereNumber('stock_write_off');
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
        Route::post('/{sale}/void', [SaleController::class, 'void'])->whereNumber('sale');
        Route::post('/{sale}/acknowledge-review', [SaleController::class, 'acknowledgeReview'])->whereNumber('sale');
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
        Route::get('/registers/current', [RegisterSessionController::class, 'current']);
        Route::post('/registers/open', [RegisterSessionController::class, 'open']);
        Route::post('/registers/{id}/close', [RegisterSessionController::class, 'close'])->whereNumber('id');
        Route::put('/registers/{id}/cart', [RegisterSessionController::class, 'updateCart'])->whereNumber('id');
        Route::get('/registers', [RegisterSessionController::class, 'index']);
        Route::get('/registers/{id}', [RegisterSessionController::class, 'show'])->whereNumber('id');
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
    | Product Option Groups (sugar level, ice level, dll) — read for cashier
    */
    Route::prefix('product-option-groups')->group(function () {
        Route::get('/', [ProductOptionGroupController::class, 'index']);
        Route::get('/{product_option_group}/products', [ProductOptionGroupController::class, 'products'])
            ->whereNumber('product_option_group');
        Route::get('/{product_option_group}', [ProductOptionGroupController::class, 'show'])
            ->whereNumber('product_option_group');
    });

    /*
    | Members (customer database) — read/lookup for cashier at checkout.
    | Point settings are read-only here so the POS can show "poin didapat".
    */
    Route::prefix('members')->group(function () {
        Route::get('/lookup', [MemberController::class, 'lookup']);
        Route::get('/settings/points', [MemberController::class, 'settings']);
    });

    /*
    | Member Store — cashiers redeem points against the reward catalog.
    */
    Route::prefix('loyalty-rewards')->group(function () {
        Route::get('/', [LoyaltyRewardController::class, 'index']);
        Route::post('/{loyalty_reward}/redeem', [LoyaltyRewardController::class, 'redeem'])
            ->whereNumber('loyalty_reward');
    });

    Route::get('product-recipes', [ProductRecipeController::class, 'index']);

    /*
    |--------------------------------------------------------------------------
    | STAFF (admin + kasir)
    |--------------------------------------------------------------------------
    */
    Route::middleware('role:admin|regional_manager|store_admin|kasir')->group(function () {

        Route::prefix('purchases')->group(function () {
            Route::get('/', [PurchaseController::class, 'index']);
            Route::get('/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase');
            Route::post('/', [PurchaseController::class, 'store']);
            Route::put('/{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase');
            Route::get('/{purchase}/for-receipt', [PurchaseReceiveController::class, 'forReceipt'])->whereNumber('purchase');
            Route::post('/{purchase}/receive', [PurchaseReceiveController::class, 'receive'])->whereNumber('purchase');
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
    Route::middleware('role:admin|regional_manager|store_admin')->group(function () {

        Route::prefix('purchases')->group(function () {
            Route::post('/{purchase}/approve', [PurchaseController::class, 'approve'])->whereNumber('purchase');
            Route::post('/{purchase}/cancel', [PurchaseController::class, 'cancel'])->whereNumber('purchase');
            Route::delete('/{purchase}/items/{item}', [PurchaseController::class, 'destroyItem'])
                ->whereNumber('purchase')
                ->whereNumber('item');
        });

        Route::prefix('receipts')->group(function () {
            Route::post('/{goodsReceipt}/void', [GoodsReceiptController::class, 'void'])->whereNumber('goodsReceipt');
            Route::post('/{goodsReceipt}/cost-adjustments', [GoodsReceiptController::class, 'costAdjust'])->whereNumber('goodsReceipt');
            Route::post('/{goodsReceipt}/review', [GoodsReceiptController::class, 'flagReview'])->whereNumber('goodsReceipt');
            Route::post('/{goodsReceipt}/review/resolve', [GoodsReceiptController::class, 'resolveReview'])->whereNumber('goodsReceipt');
        });

        Route::prefix('categories')->group(function () {
            Route::post('/', [CategoryController::class, 'store']);
            Route::match(['put', 'patch'], '/{category}', [CategoryController::class, 'update'])->whereNumber('category');
            Route::delete('/{category}', [CategoryController::class, 'destroy'])->whereNumber('category');
        });

        Route::prefix('sub-categories')->group(function () {
            Route::post('/', [SubCategoryController::class, 'store']);
            Route::put('/{sub_category}', [SubCategoryController::class, 'update'])->whereNumber('sub_category');
            Route::delete('/{sub_category}', [SubCategoryController::class, 'destroy'])->whereNumber('sub_category');
        });

        Route::prefix('products')->group(function () {
            Route::post('/', [ProductController::class, 'store']);
            Route::match(['put', 'patch'], '/{product}', [ProductController::class, 'update'])->whereNumber('product');
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

        Route::prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::get('/roles/options', [UserController::class, 'roleOptions']);
            Route::get('/{user}', [UserController::class, 'show'])->whereNumber('user');
            Route::post('/', [UserController::class, 'store']);
            Route::match(['put', 'patch'], '/{user}', [UserController::class, 'update'])->whereNumber('user');
            Route::delete('/{user}', [UserController::class, 'destroy'])->whereNumber('user');
            Route::patch('/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user');
            Route::post('/{user}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('user');
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

        Route::apiResource('units', UnitController::class)->only(['store', 'update', 'destroy']);

        Route::post('product-recipes', [ProductRecipeController::class, 'store']);
        Route::get('product-recipes/{product_recipe}', [ProductRecipeController::class, 'show']);
        Route::put('product-recipes/{product_recipe}', [ProductRecipeController::class, 'update']);
        Route::delete('product-recipes/{product_recipe}', [ProductRecipeController::class, 'destroy']);

        Route::prefix('discounts')->group(function () {
            Route::post('/', [DiscountController::class, 'store']);
            Route::put('/{discount}', [DiscountController::class, 'update']);
            Route::delete('/{discount}', [DiscountController::class, 'destroy']);
            Route::patch('/{discount}/toggle', [DiscountController::class, 'toggle']);
        });

        Route::prefix('product-option-groups')->group(function () {
            Route::post('/', [ProductOptionGroupController::class, 'store']);
            Route::match(['put', 'patch'], '/{product_option_group}/products', [ProductOptionGroupController::class, 'syncProducts'])
                ->whereNumber('product_option_group');
            Route::match(['put', 'patch'], '/{product_option_group}', [ProductOptionGroupController::class, 'update'])
                ->whereNumber('product_option_group');
            Route::delete('/{product_option_group}', [ProductOptionGroupController::class, 'destroy'])
                ->whereNumber('product_option_group');
        });

        /*
        | Members (customer database) — management CRUD + point settings.
        | Static segments are declared BEFORE /{member} so "next-code" and
        | "settings" are never swallowed by the numeric binding.
        */
        Route::prefix('members')->group(function () {
            Route::get('/', [MemberController::class, 'index']);
            Route::get('/next-code', [MemberController::class, 'nextCode']);
            Route::match(['put', 'patch'], '/settings/points', [MemberController::class, 'updateSettings']);

            Route::post('/', [MemberController::class, 'store']);
            Route::get('/{member}', [MemberController::class, 'show'])->whereNumber('member');
            Route::match(['put', 'patch'], '/{member}', [MemberController::class, 'update'])->whereNumber('member');
            Route::delete('/{member}', [MemberController::class, 'destroy'])->whereNumber('member');

            Route::get('/{member}/points', [MemberController::class, 'pointHistory'])->whereNumber('member');
            Route::post('/{member}/points', [MemberController::class, 'adjustPoints'])->whereNumber('member');
        });

        Route::prefix('loyalty-rewards')->group(function () {
            Route::post('/', [LoyaltyRewardController::class, 'store']);
            Route::put('/{loyalty_reward}', [LoyaltyRewardController::class, 'update'])->whereNumber('loyalty_reward');
            Route::patch('/{loyalty_reward}', [LoyaltyRewardController::class, 'update'])->whereNumber('loyalty_reward');
            Route::delete('/{loyalty_reward}', [LoyaltyRewardController::class, 'destroy'])->whereNumber('loyalty_reward');
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

        Route::prefix('settings')->group(function () {
            Route::get('/payment-request-signatories', [AppSettingController::class, 'paymentRequestSignatories']);
            Route::put('/payment-request-signatories', [AppSettingController::class, 'updatePaymentRequestSignatories']);
            Route::post('/payment-request-signatories/upload', [AppSettingController::class, 'uploadSignature']);

            Route::get('/payment-request-signers', [AppSettingController::class, 'listSigners']);
            Route::post('/payment-request-signers', [AppSettingController::class, 'storeSigner']);
            Route::put('/payment-request-signers/{id}', [AppSettingController::class, 'updateSigner'])->whereNumber('id');
            Route::delete('/payment-request-signers/{id}', [AppSettingController::class, 'destroySigner'])->whereNumber('id');

            Route::get('/void-security-code', [AppSettingController::class, 'voidSecurityCode']);
            Route::put('/void-security-code', [AppSettingController::class, 'updateVoidSecurityCode']);
        });

    });

});
