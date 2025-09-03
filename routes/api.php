<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockLogController;


// Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');


Route::middleware(['auth:sanctum'])->group(function() {
    // Categories
    Route::apiResource('categories', CategoryController::class);
    Route::post('/categories/{id}/restore', [CategoryController::class,'restore']);


    // Products
    Route::apiResource('products', ProductController::class);
    Route::post('/products/{id}/restore', [ProductController::class,'restore']);
    Route::post('/products/{product}/upload', [ProductController::class,'upload']);


    // Stock
    Route::post('/products/{product}/stock/change', [StockController::class,'change']);


    // Stock Logs
    Route::apiResource('stock-logs', StockLogController::class)->only(['index','show']);
});