<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\OrderController as StorefrontOrderController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\Mobile\CategoryController;
use App\Http\Controllers\Api\Mobile\HealthController;
use App\Http\Controllers\Api\Mobile\HomeController;
use App\Http\Controllers\Api\Mobile\MediaController;
use App\Http\Controllers\Api\Mobile\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/media', MediaController::class);

Route::get('/home', HomeController::class);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/google', [AuthController::class, 'googleAuth']);
    Route::post('/google/complete-phone', [AuthController::class, 'completeGooglePhone']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/verify-otp', [AuthController::class, 'verifyCustomerLoginOtp']);
});

Route::post('/coupons/validate', [CouponController::class, 'validateCode']);
Route::post('/orders', [StorefrontOrderController::class, 'store']);
Route::post('/orders/incomplete', [StorefrontOrderController::class, 'storeIncomplete']);
Route::post('/orders/send-otp', [StorefrontOrderController::class, 'sendOtp']);
Route::post('/orders/verify-otp', [StorefrontOrderController::class, 'verifyOtp']);
Route::post('/orders/track', [StorefrontOrderController::class, 'track']);

Route::middleware('jwt.auth')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy']);

    Route::get('/account/orders', [StorefrontOrderController::class, 'index']);
    Route::patch('/account/profile', [AuthController::class, 'updateProfile']);
    Route::post('/account/avatar', [AuthController::class, 'uploadAvatar']);
    Route::patch('/account/password', [AuthController::class, 'updatePassword']);
});
