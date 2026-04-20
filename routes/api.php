<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\FacebookMarketingController as AdminFacebookMarketingController;
use App\Http\Controllers\Api\Admin\GoogleMarketingController as AdminGoogleMarketingController;
use App\Http\Controllers\Api\Admin\SeoMarketingController as AdminSeoMarketingController;
use App\Http\Controllers\Api\Admin\AttributeController as AdminAttributeController;
use App\Http\Controllers\Api\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SliderController as AdminSliderController;
use App\Http\Controllers\Api\Admin\TagController as AdminTagController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\FacebookMarketingController;
use App\Http\Controllers\Api\GoogleMarketingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SeoMarketingController;
use App\Http\Controllers\Api\SliderController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
]));

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
});

Route::get('/home', [StorefrontController::class, 'index']);
Route::get('/sliders', [SliderController::class, 'index']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/marketing/facebook', [FacebookMarketingController::class, 'show']);
Route::get('/marketing/google', [GoogleMarketingController::class, 'show']);
Route::get('/marketing/seo', [SeoMarketingController::class, 'show']);
Route::post('/contact-messages', [ContactMessageController::class, 'store']);
Route::get('/pages/{slug}', [PageController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/reviews', [ReviewController::class, 'index']);
Route::post('/reviews', [ReviewController::class, 'store']);
Route::get('/tags', [TagController::class, 'index']);
Route::post('/coupons/validate', [CouponController::class, 'validateCode']);
Route::post('/orders', [OrderController::class, 'store']);
Route::post('/orders/track', [OrderController::class, 'track']);

Route::middleware('jwt.auth')->group(function (): void {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/wishlist', [WishlistController::class, 'index']);
    Route::post('/wishlist', [WishlistController::class, 'store']);
    Route::delete('/wishlist/{product}', [WishlistController::class, 'destroy']);

    Route::get('/account/orders', [OrderController::class, 'index']);
    Route::patch('/account/profile', [AuthController::class, 'updateProfile']);
    Route::post('/account/avatar', [AuthController::class, 'uploadAvatar']);
    Route::patch('/account/password', [AuthController::class, 'updatePassword']);

    Route::prefix('admin')->middleware('admin')->group(function (): void {
        Route::get('/dashboard', AdminDashboardController::class);
        Route::apiResource('products', AdminProductController::class);
        Route::apiResource('sliders', AdminSliderController::class);
        Route::apiResource('categories', AdminCategoryController::class);
        Route::apiResource('tags', AdminTagController::class);
        Route::apiResource('attributes', AdminAttributeController::class);
        Route::post('/attributes/{attribute}/terms', [AdminAttributeController::class, 'storeTerm']);
        Route::patch('/attribute-terms/{term}', [AdminAttributeController::class, 'updateTerm']);
        Route::delete('/attribute-terms/{term}', [AdminAttributeController::class, 'destroyTerm']);
        Route::apiResource('coupons', AdminCouponController::class);
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::patch('/orders/{order}', [AdminOrderController::class, 'update']);
        Route::get('/customers', [AdminCustomerController::class, 'index']);
        Route::get('/customers/{customer}', [AdminCustomerController::class, 'show']);
        Route::patch('/customers/{customer}', [AdminCustomerController::class, 'update']);
        Route::get('/contact-messages', [AdminContactMessageController::class, 'index']);
        Route::patch('/contact-messages/{contactMessage}', [AdminContactMessageController::class, 'update']);
        Route::delete('/contact-messages/{contactMessage}', [AdminContactMessageController::class, 'destroy']);
        Route::apiResource('pages', AdminPageController::class);
        Route::get('/marketing/facebook', [AdminFacebookMarketingController::class, 'show']);
        Route::patch('/marketing/facebook', [AdminFacebookMarketingController::class, 'update']);
        Route::get('/marketing/google', [AdminGoogleMarketingController::class, 'show']);
        Route::patch('/marketing/google', [AdminGoogleMarketingController::class, 'update']);
        Route::get('/marketing/seo', [AdminSeoMarketingController::class, 'show']);
        Route::patch('/marketing/seo', [AdminSeoMarketingController::class, 'update']);
        Route::apiResource('reviews', AdminReviewController::class)->only([
            'index',
            'store',
            'update',
            'destroy',
        ]);
        Route::get('/media', [AdminMediaController::class, 'index']);
        Route::post('/media', [AdminMediaController::class, 'store']);
        Route::delete('/media/{mediaAsset}', [AdminMediaController::class, 'destroy']);
    });
});
