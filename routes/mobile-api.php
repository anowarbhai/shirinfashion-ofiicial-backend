<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\CustomerAuthSettingsController;
use App\Http\Controllers\Api\OrderController as StorefrontOrderController;
use App\Http\Controllers\Api\ProductPageSettingsController;
use App\Http\Controllers\Api\WishlistController;
use App\Http\Controllers\Api\Mobile\CartController;
use App\Http\Controllers\Api\Mobile\AppStatusController;
use App\Http\Controllers\Api\Mobile\CategoryController;
use App\Http\Controllers\Api\Mobile\DeviceTokenController;
use App\Http\Controllers\Api\Mobile\HealthController;
use App\Http\Controllers\Api\Mobile\HomeController;
use App\Http\Controllers\Api\Mobile\MediaController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\ProductController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/media', MediaController::class);
Route::get('/app-status', AppStatusController::class)->middleware('throttle:mobile-sync');

Route::get('/home', HomeController::class);
Route::get('/settings/customer-auth', [CustomerAuthSettingsController::class, 'show']);
Route::get('/settings/product-page', [ProductPageSettingsController::class, 'show']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{slug}', [ProductController::class, 'show']);
Route::post('/device-tokens', [DeviceTokenController::class, 'store'])->middleware('throttle:mobile-sync');
Route::delete('/device-tokens', [DeviceTokenController::class, 'destroy'])->middleware('throttle:mobile-sync');
Route::post('/cart', [CartController::class, 'sync'])->middleware('throttle:mobile-sync');
Route::post('/cart/clear', [CartController::class, 'clear'])->middleware('throttle:mobile-sync');

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-login');
    Route::post('/google', [AuthController::class, 'googleAuth'])->middleware('throttle:auth-login');
    Route::post('/google/complete-phone', [AuthController::class, 'completeGooglePhone'])->middleware('throttle:auth-login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/login/verify-otp', [AuthController::class, 'verifyCustomerLoginOtp'])->middleware('throttle:otp-verify');
});

Route::post('/coupons/validate', [CouponController::class, 'validateCode'])->middleware('throttle:public-write');
Route::post('/orders', [StorefrontOrderController::class, 'store'])->middleware('throttle:checkout');
Route::post('/orders/incomplete', [StorefrontOrderController::class, 'storeIncomplete'])->middleware('throttle:checkout');
Route::post('/orders/send-otp', [StorefrontOrderController::class, 'sendOtp'])->middleware('throttle:otp-send');
Route::post('/orders/verify-otp', [StorefrontOrderController::class, 'verifyOtp'])->middleware('throttle:otp-verify');
Route::post('/orders/track', [StorefrontOrderController::class, 'track'])->middleware('throttle:order-track');
Route::match(['get', 'post'], '/payments/sslcommerz/success', [StorefrontOrderController::class, 'sslCommerzSuccess'])->middleware('throttle:public-write');
Route::match(['get', 'post'], '/payments/sslcommerz/fail', [StorefrontOrderController::class, 'sslCommerzFail'])->middleware('throttle:public-write');
Route::match(['get', 'post'], '/payments/sslcommerz/cancel', [StorefrontOrderController::class, 'sslCommerzCancel'])->middleware('throttle:public-write');
Route::post('/payments/sslcommerz/ipn', [StorefrontOrderController::class, 'sslCommerzIpn'])->middleware('throttle:public-write');
Route::get('/notifications/public', [NotificationController::class, 'publicIndex']);

Route::middleware('jwt.auth')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::post('/account/device-tokens', [DeviceTokenController::class, 'store'])->middleware('throttle:mobile-sync');
    Route::delete('/account/device-tokens', [DeviceTokenController::class, 'destroy'])->middleware('throttle:mobile-sync');
    Route::post('/account/cart', [CartController::class, 'sync'])->middleware('throttle:mobile-sync');
    Route::post('/account/cart/clear', [CartController::class, 'clear'])->middleware('throttle:mobile-sync');

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy']);

    Route::get('/account/orders', [StorefrontOrderController::class, 'index']);
    Route::patch('/account/profile', [AuthController::class, 'updateProfile']);
    Route::post('/account/avatar', [AuthController::class, 'uploadAvatar']);
    Route::patch('/account/password', [AuthController::class, 'updatePassword']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});
