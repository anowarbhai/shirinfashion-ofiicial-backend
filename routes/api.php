<?php

use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CheckoutGuardSettingsController as AdminCheckoutGuardSettingsController;
use App\Http\Controllers\Api\Admin\CouponController as AdminCouponController;
use App\Http\Controllers\Api\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Api\Admin\CustomerController as AdminCustomerController;
use App\Http\Controllers\Api\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Api\Admin\FraudCheckerSettingsController as AdminFraudCheckerSettingsController;
use App\Http\Controllers\Api\Admin\FacebookMarketingController as AdminFacebookMarketingController;
use App\Http\Controllers\Api\Admin\GeneralSettingsController as AdminGeneralSettingsController;
use App\Http\Controllers\Api\Admin\GoogleMarketingController as AdminGoogleMarketingController;
use App\Http\Controllers\Api\Admin\MailSetupSettingsController as AdminMailSetupSettingsController;
use App\Http\Controllers\Api\Admin\AdminPermissionController as AdminPermissionController;
use App\Http\Controllers\Api\Admin\AdminAuditLogController as AdminAuditLogController;
use App\Http\Controllers\Api\Admin\AdminRoleController as AdminRoleController;
use App\Http\Controllers\Api\Admin\SeoMarketingController as AdminSeoMarketingController;
use App\Http\Controllers\Api\Admin\SmsIntegrationSettingsController as AdminSmsIntegrationSettingsController;
use App\Http\Controllers\Api\Admin\AttributeController as AdminAttributeController;
use App\Http\Controllers\Api\Admin\MediaController as AdminMediaController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\PageController as AdminPageController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ProductPageSettingsController as AdminProductPageSettingsController;
use App\Http\Controllers\Api\Admin\ProductVolumeDiscountController as AdminProductVolumeDiscountController;
use App\Http\Controllers\Api\Admin\ReviewController as AdminReviewController;
use App\Http\Controllers\Api\Admin\SliderController as AdminSliderController;
use App\Http\Controllers\Api\Admin\TagController as AdminTagController;
use App\Http\Controllers\Api\Admin\TeamMemberController as AdminTeamMemberController;
use App\Http\Controllers\Api\Admin\ThemeAppearanceController as AdminThemeAppearanceController;
use App\Http\Controllers\Api\Admin\ThemeFooterController as AdminThemeFooterController;
use App\Http\Controllers\Api\Admin\ThemeHeaderController as AdminThemeHeaderController;
use App\Http\Controllers\Api\Admin\ThemeMenuController as AdminThemeMenuController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ContactMessageController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\FacebookMarketingController;
use App\Http\Controllers\Api\GoogleMarketingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductPageSettingsController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SeoMarketingController;
use App\Http\Controllers\Api\SmsIntegrationController;
use App\Http\Controllers\Api\SliderController;
use App\Http\Controllers\Api\StorefrontController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\ThemeController;
use App\Http\Controllers\Api\WishlistController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'app' => config('app.name'),
]));

Route::prefix('auth')->group(function (): void {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/login/verify-otp', [AuthController::class, 'verifyCustomerLoginOtp']);
    Route::post('/admin/login', [AuthController::class, 'adminLogin']);
    Route::post('/admin/login/verify-otp', [AuthController::class, 'verifyAdminLoginOtp']);
});

Route::get('/home', [StorefrontController::class, 'index']);
Route::get('/sliders', [SliderController::class, 'index']);
Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/marketing/facebook', [FacebookMarketingController::class, 'show']);
Route::get('/marketing/google', [GoogleMarketingController::class, 'show']);
Route::get('/marketing/seo', [SeoMarketingController::class, 'show']);
Route::get('/settings/sms-integration/public', [SmsIntegrationController::class, 'publicConfig']);
Route::get('/theme', [ThemeController::class, 'show']);
Route::post('/contact-messages', [ContactMessageController::class, 'store']);
Route::get('/pages/{slug}', [PageController::class, 'show']);
Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{product}', [ProductController::class, 'show']);
Route::get('/product-page-settings', [ProductPageSettingsController::class, 'show']);
Route::get('/reviews', [ReviewController::class, 'index']);
Route::post('/reviews', [ReviewController::class, 'store']);
Route::get('/tags', [TagController::class, 'index']);
Route::post('/coupons/validate', [CouponController::class, 'validateCode']);
Route::post('/orders', [OrderController::class, 'store']);
Route::post('/orders/incomplete', [OrderController::class, 'storeIncomplete']);
Route::post('/orders/send-otp', [OrderController::class, 'sendOtp']);
Route::post('/orders/verify-otp', [OrderController::class, 'verifyOtp']);
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

    Route::prefix('admin')->middleware(['admin', 'admin.permission'])->group(function (): void {
        Route::get('/dashboard', AdminDashboardController::class);
        Route::apiResource('products', AdminProductController::class);
        Route::get('/product-page-settings', [AdminProductPageSettingsController::class, 'show']);
        Route::patch('/product-page-settings', [AdminProductPageSettingsController::class, 'update']);
        Route::get('/products/{productIdentifier}/volume-discounts', [AdminProductVolumeDiscountController::class, 'index']);
        Route::put('/products/{productIdentifier}/volume-discounts', [AdminProductVolumeDiscountController::class, 'update']);
        Route::apiResource('sliders', AdminSliderController::class);
        Route::apiResource('categories', AdminCategoryController::class);
        Route::apiResource('tags', AdminTagController::class);
        Route::apiResource('attributes', AdminAttributeController::class);
        Route::post('/attributes/{attribute}/terms', [AdminAttributeController::class, 'storeTerm']);
        Route::patch('/attribute-terms/{term}', [AdminAttributeController::class, 'updateTerm']);
        Route::delete('/attribute-terms/{term}', [AdminAttributeController::class, 'destroyTerm']);
        Route::apiResource('coupons', AdminCouponController::class);
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::post('/orders', [AdminOrderController::class, 'store']);
        Route::patch('/orders/{order}', [AdminOrderController::class, 'update']);
        Route::get('/customers', [AdminCustomerController::class, 'index']);
        Route::post('/customers', [AdminCustomerController::class, 'store']);
        Route::get('/customers/{customer}', [AdminCustomerController::class, 'show']);
        Route::patch('/customers/{customer}', [AdminCustomerController::class, 'update']);
        Route::delete('/customers/{customer}', [AdminCustomerController::class, 'destroy']);
        Route::apiResource('team-members', AdminTeamMemberController::class);
        Route::get('/audit-logs', [AdminAuditLogController::class, 'index']);
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
        Route::get('/themes/appearance', [AdminThemeAppearanceController::class, 'show']);
        Route::patch('/themes/appearance', [AdminThemeAppearanceController::class, 'update']);
        Route::get('/themes/header', [AdminThemeHeaderController::class, 'show']);
        Route::patch('/themes/header', [AdminThemeHeaderController::class, 'update']);
        Route::get('/themes/footer', [AdminThemeFooterController::class, 'show']);
        Route::patch('/themes/footer', [AdminThemeFooterController::class, 'update']);
        Route::get('/themes/menus', [AdminThemeMenuController::class, 'index']);
        Route::post('/themes/menus', [AdminThemeMenuController::class, 'store']);
        Route::get('/themes/menus/{menu}', [AdminThemeMenuController::class, 'show']);
        Route::patch('/themes/menus/{menu}', [AdminThemeMenuController::class, 'update']);
        Route::delete('/themes/menus/{menu}', [AdminThemeMenuController::class, 'destroy']);
        Route::get('/settings/general', [AdminGeneralSettingsController::class, 'show']);
        Route::patch('/settings/general', [AdminGeneralSettingsController::class, 'update']);
        Route::get('/settings/fraud-checker', [AdminFraudCheckerSettingsController::class, 'show']);
        Route::patch('/settings/fraud-checker', [AdminFraudCheckerSettingsController::class, 'update']);
        Route::post('/settings/fraud-checker/test', [AdminFraudCheckerSettingsController::class, 'test']);
        Route::get('/settings/checkout-guard', [AdminCheckoutGuardSettingsController::class, 'show']);
        Route::patch('/settings/checkout-guard', [AdminCheckoutGuardSettingsController::class, 'update']);
        Route::get('/settings/mail-setup', [AdminMailSetupSettingsController::class, 'show']);
        Route::patch('/settings/mail-setup', [AdminMailSetupSettingsController::class, 'update']);
        Route::post('/settings/mail-setup/test', [AdminMailSetupSettingsController::class, 'test']);
        Route::get('/settings/sms-integration', [AdminSmsIntegrationSettingsController::class, 'show']);
        Route::patch('/settings/sms-integration', [AdminSmsIntegrationSettingsController::class, 'update']);
        Route::get('/settings/sms-integration/balance', [AdminSmsIntegrationSettingsController::class, 'balance']);
        Route::post('/settings/sms-integration/test', [AdminSmsIntegrationSettingsController::class, 'sendTest']);
        Route::get('/settings/roles', [AdminRoleController::class, 'index']);
        Route::post('/settings/roles', [AdminRoleController::class, 'store']);
        Route::patch('/settings/roles/{role}', [AdminRoleController::class, 'update']);
        Route::delete('/settings/roles/{role}', [AdminRoleController::class, 'destroy']);
        Route::get('/settings/permissions', [AdminPermissionController::class, 'index']);
        Route::post('/settings/permissions', [AdminPermissionController::class, 'store']);
        Route::patch('/settings/permissions/{permission}', [AdminPermissionController::class, 'update']);
        Route::delete('/settings/permissions/{permission}', [AdminPermissionController::class, 'destroy']);
        Route::patch('/settings/roles/{role}/permissions', [AdminPermissionController::class, 'updateRolePermissions']);
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
