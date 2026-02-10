<?php

use App\Http\Controllers\UserController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\BrandController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ShopController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\DeliveryAddressController;
use App\Http\Controllers\WishListController;
use App\Http\Controllers\BannerController;
use App\Http\Controllers\AttributeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProductAttributeController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ProductDiscountController;
use App\Http\Middleware\ApiTokenAuth;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\RelatedProductController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\FacebookPostController;

// Authentication endpoints
Route::post('/auth/login', [AuthController::class, 'login'])->withoutMiddleware('token');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/tokens', [AuthController::class, 'listTokens']);
Route::delete('/auth/tokens/{id}', [AuthController::class, 'revokeToken']);

Route::prefix('users')->group(function () {
    Route::post('/create', [UserController::class, 'createUser'])->withoutMiddleware('token');;

    Route::get('/list', [UserController::class, 'listUsers']);
    Route::get('/customers', [UserController::class, 'getCustomers']);
    Route::get('/vendors', [UserController::class, 'getVendors']);
    Route::get('/delivery-men', [UserController::class, 'getDeliveryMan']);
    Route::get('/details/{id}', [UserController::class, 'getUserDetails']);

    Route::put('/update/{id}', [UserController::class, 'updateUser']);

    Route::patch('/ban/{id}', [UserController::class, 'banUser']);
    Route::patch('/unban/{id}', [UserController::class, 'unbanUser']);

    Route::delete('/delete/{id}', [UserController::class, 'deleteUser']);
});

Route::prefix('categories')->group(function () {
    Route::post('/create', [CategoryController::class, 'createCategory']);

    Route::get('/list', [CategoryController::class, 'listCategories']);
    Route::get('/details/{id}', [CategoryController::class, 'getCategoryDetails']);
    Route::get('/children/{id}', [CategoryController::class, 'getCategoryChildren']);
    Route::get('/with-children', [CategoryController::class, 'getCategoryWithAllChildren']);

    Route::put('/update/{id}', [CategoryController::class, 'updateCategory']);

    Route::delete('/delete/{id}', [CategoryController::class, 'deleteCategory']);
});

Route::prefix('brands')->group(function () {
    Route::post('/create', [BrandController::class, 'createBrand']);

    Route::get('/list', [BrandController::class, 'listBrands']);
    Route::get('/details/{id}', [BrandController::class, 'getBrandDetails']);

    Route::put('/update/{id}', [BrandController::class, 'updateBrand']);

    Route::delete('/delete/{id}', [BrandController::class, 'deleteBrand']);
});

Route::prefix('products')->group(function () {
    Route::post('/create', [ProductController::class, 'createProduct']);
    Route::post('/images/upload/{productId}', [ProductController::class, 'productImageUpload']);

    Route::get('/list', [ProductController::class, 'listProducts']);
    Route::get('/list/featured', [ProductController::class, 'listFeaturedProducts']);
    Route::get('/list/today-deal', [ProductController::class, 'listTodayDealProducts']);
    Route::get('/details/{id}', [ProductController::class, 'getProductDetails']);
    Route::post('/update/{id}', [ProductController::class, 'updateProduct']);
    Route::delete('/delete/{id}', [ProductController::class, 'deleteProduct']);
    // Images
    Route::post('/images/add/{id}', [ProductController::class, 'addProductImage']);
    Route::delete('/images/delete/{imageId}', [ProductController::class, 'deleteProductImage']);
});



Route::prefix('shops')->group(function () {
    Route::post('/create', [ShopController::class, 'createShop']);

    Route::get('/list', [ShopController::class, 'listShops']);
    Route::get('/details/{id}', [ShopController::class, 'getShopDetails']);
    Route::get('/products/{id}', [ShopController::class, 'getShopProducts']);

    Route::post('/update/{id}', [ShopController::class, 'updateShop']);

    Route::patch('/status/{id}', [ShopController::class, 'updateShopStatus']);

    Route::delete('/delete/{id}', [ShopController::class, 'deleteShop']);
});





Route::prefix('carts')->group(function () {
    Route::get('/active/{userId}', [CartController::class, 'getActiveCart']);

    Route::post('/items/add', [CartController::class, 'addItemToCart']);
    Route::put('/items/update/{itemId}', [CartController::class, 'updateCartItemQty']);
    Route::delete('/items/delete/{itemId}', [CartController::class, 'removeCartItem']);

    Route::delete('/clear/{userId}', [CartController::class, 'clearCart']);
});





Route::prefix('orders')->group(function () {
    Route::post('/checkout', [OrderController::class, 'checkout']);

    Route::get('/list/{userId}', [OrderController::class, 'listOrdersByUser']);
    Route::get('/all/orders', [OrderController::class, 'allOrders']);

    // Completed orders
    Route::get('/completed', [OrderController::class, 'completedOrders']);
    Route::get('/completed/{userId}', [OrderController::class, 'completedOrdersByUser']);

    Route::get('/details/{id}', [OrderController::class, 'getOrderDetails']);

    Route::patch('/status/{id}', [OrderController::class, 'updateOrderStatus']);

    // Item status update (for vendor/admin workflows)
    Route::patch('/item/status/{id}', [OrderController::class, 'updateOrderItemStatus']);
});

Route::prefix('addresses')->group(function () {
    Route::post('/add', [DeliveryAddressController::class, 'addDeliveryAddress']);
    Route::get('/user/{userId}', [DeliveryAddressController::class, 'getAddressByUser']);
    Route::delete('/delete/{id}', [DeliveryAddressController::class, 'deleteAddress']);
    Route::patch('/inactive/{id}', [DeliveryAddressController::class, 'inactiveAddress']);
    Route::put('/update/{id}', [DeliveryAddressController::class, 'updateAddress']);
});


// Wishlist endpoints
Route::prefix('wishlists')->group(function () {
    Route::post('/add', [WishListController::class, 'addWishProduct']);
    Route::get('/list/{userId}', [WishListController::class, 'getWishList']);
    Route::delete('/delete/{id}', [WishListController::class, 'deleteWishedProduct']);
});

// Related products endpoints
Route::prefix('related-products')->group(function () {
    Route::post('/add', [RelatedProductController::class, 'addRelatedProduct']);
    Route::get('/list/{productId}', [RelatedProductController::class, 'getRelatedProduct']);
    Route::delete('/remove/{id}', [RelatedProductController::class, 'remove']);
});

// Review endpoints
Route::prefix('reviews')->group(function () {
    Route::post('/add', [ReviewController::class, 'addReview']);
    Route::get('/list', [ReviewController::class, 'getAllReview']);
    Route::get('/product/{productId}', [ReviewController::class, 'getReviewByProduct']);
    Route::get('/user/{userId}', [ReviewController::class, 'getReviewByUser']);
    Route::put('/update-by-user/{id}', [ReviewController::class, 'updateReviewByUser']);
    Route::delete('/remove/{id}', [ReviewController::class, 'removeReview']);
});


// Banner endpoints
Route::prefix('banners')->group(function () {
    Route::post('/add', [BannerController::class, 'addBanner']);
    Route::get('/active', [BannerController::class, 'getActiveBanner']);
    Route::delete('/remove/{id}', [BannerController::class, 'removeBanner']);
});


Route::prefix('attributes')->group(function () {
    Route::post('/create', [AttributeController::class, 'addAttribute']);
    Route::get('/list', [AttributeController::class, 'getAttributes']);
    Route::get('/details/{id}', [AttributeController::class, 'getAttributeWithValues']);
    Route::put('/update/{id}', [AttributeController::class, 'updateAttribute']);
    Route::delete('/delete/{id}', [AttributeController::class, 'deleteAttribute']);

    // Attribute Values
    Route::post('/values/create', [AttributeController::class, 'addAttributeValue']);
    Route::put('/values/update/{id}', [AttributeController::class, 'updateAttributeValue']);
    Route::delete('/values/delete/{id}', [AttributeController::class, 'deleteAttributeValue']);
});

Route::prefix('product-attributes')->group(function () {
    Route::post('/create', [ProductAttributeController::class, 'create']);
    Route::get('/list', [ProductAttributeController::class, 'list']);
    Route::get('/details/{id}', [ProductAttributeController::class, 'details']);
    Route::put('/update/{id}', [ProductAttributeController::class, 'update']);
    Route::delete('/delete/{id}', [ProductAttributeController::class, 'delete']);
});

Route::prefix('reports')->group(function () {
    Route::get('/dashboard', [ReportController::class, 'dashboard']);
});

Route::prefix('product-discounts')->group(function () {
    Route::post('/create', [ProductDiscountController::class, 'create']);
    Route::get('/list', [ProductDiscountController::class, 'list']);
    Route::get('/details/{id}', [ProductDiscountController::class, 'details']);
    Route::put('/update/{id}', [ProductDiscountController::class, 'update']);
    Route::delete('/delete/{id}', [ProductDiscountController::class, 'delete']);
});

// Uploads
Route::prefix('uploads')->group(function () {
    Route::post('/image', [UploadController::class, 'uploadImage']);
    Route::get('/list', [UploadController::class, 'listUploads']);
    Route::get('/{id}', [UploadController::class, 'getUpload']);
    Route::delete('/{id}', [UploadController::class, 'deleteUpload']);
});

Route::prefix('deliveries')->group(function () {
    Route::post('/assign', [DeliveryController::class, 'assignDeliveryMan']);
    Route::post('/unassign', [DeliveryController::class, 'unassignDeliveryMan']);
    Route::get('/all/{deliveryManId}', [DeliveryController::class, 'getAllOrderByDeliveryMan']);
    Route::get('/assigned/{deliveryManId}', [DeliveryController::class, 'getAssignedDelivery']);
    Route::get('/completed/{deliveryManId}', [DeliveryController::class, 'getCompletedDelivery']);
});

Route::prefix('transactions')->group(function () {
    Route::get('/credit', [TransactionController::class, 'creditTransaction']);
    Route::get('/debit', [TransactionController::class, 'debitTransaction']);
    Route::get('/report', [TransactionController::class, 'transactionReport']);
});

// Facebook endpoints
Route::prefix('facebook')->group(function () {
    Route::post('/accounts/add', [FacebookPostController::class, 'addAccount']);
    Route::get('/accounts', [FacebookPostController::class, 'getAccounts']);

    Route::post('/pages/add', [FacebookPostController::class, 'addPage']);
    Route::get('/pages', [FacebookPostController::class, 'getPages']);

    Route::post('/posts/publish', [FacebookPostController::class, 'publishContent']);
    Route::get('/posts', [FacebookPostController::class, 'getContents']);
    Route::post('/posts/republish/{id}', [FacebookPostController::class, 'rePublish']);
    Route::delete('/posts/delete/{id}', [FacebookPostController::class, 'deleteContent']);
});
