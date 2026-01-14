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
use App\Http\Middleware\ApiTokenAuth;

// Authentication endpoints
Route::post('/auth/login', [AuthController::class, 'login'])->withoutMiddleware('sso');
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/tokens', [AuthController::class, 'listTokens']);
Route::delete('/auth/tokens/{id}', [AuthController::class, 'revokeToken']);

Route::prefix('users')->group(function () {
    Route::post('/create', [UserController::class, 'createUser']);

    Route::get('/list', [UserController::class, 'listUsers']);
    Route::get('/customers', [UserController::class, 'getCustomers']);
    Route::get('/vendors', [UserController::class, 'getVendors']);
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
    Route::get('/details/{id}', [ProductController::class, 'getProductDetails']);

    Route::put('/update/{id}', [ProductController::class, 'updateProduct']);

    Route::delete('/delete/{id}', [ProductController::class, 'deleteProduct']);

    // Images
    Route::post('/images/add/{id}', [ProductController::class, 'addProductImage']);
    Route::delete('/images/delete/{imageId}', [ProductController::class, 'deleteProductImage']);
});



Route::prefix('shops')->group(function () {
    Route::post('/create', [ShopController::class, 'createShop']);

    Route::get('/list', [ShopController::class, 'listShops']);
    Route::get('/details/{id}', [ShopController::class, 'getShopDetails']);

    Route::put('/update/{id}', [ShopController::class, 'updateShop']);

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

