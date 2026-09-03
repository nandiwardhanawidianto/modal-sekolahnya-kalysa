<?php
use App\Http\Controllers\AdsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClosingController;
use App\Http\Controllers\CostController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SystemController;
use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function () {
    Route::get('/csrf-token',[AuthController::class,'csrf']);
    Route::post('/login',[AuthController::class,'login'])->middleware('throttle:10,1');
    Route::middleware('auth')->group(function () {
        Route::get('/me',[AuthController::class,'me']);
        Route::post('/logout',[AuthController::class,'logout']);
        Route::post('/password',[AuthController::class,'changePassword']);
        Route::get('/system/health',[SystemController::class,'health']);
        Route::get('/stores',[StoreController::class,'index']);
        Route::post('/stores',[StoreController::class,'store']);
        Route::put('/stores/{store}',[StoreController::class,'update']);
        Route::get('/stores/{store}/products',[ProductController::class,'index']);
        Route::post('/stores/{store}/costs/variants',[CostController::class,'variantCosts']);
        Route::get('/stores/{store}/fees',[CostController::class,'feeHistory']);
        Route::post('/stores/{store}/fees',[CostController::class,'storeFee']);
        Route::post('/stores/{store}/imports/preview',[ImportController::class,'preview']);
        Route::post('/stores/{store}/imports/products',[ImportController::class,'products']);
        Route::post('/stores/{store}/imports/orders',[ImportController::class,'orders']);
        Route::post('/stores/{store}/imports/income',[ImportController::class,'income']);
        Route::post('/stores/{store}/imports/ads',[ImportController::class,'ads']);
        Route::get('/stores/{store}/imports',[ImportController::class,'history']);
        Route::post('/stores/{store}/imports/{batch}/rollback',[ImportController::class,'rollback']);
        Route::get('/stores/{store}/ads',[AdsController::class,'index']);
        Route::post('/stores/{store}/ads/range',[AdsController::class,'storeRange']);
        Route::delete('/stores/{store}/ads/{period}',[AdsController::class,'destroy']);
        Route::get('/stores/{store}/report',[ReportController::class,'store']);
        Route::get('/stores/{store}/cashflow',[ReportController::class,'cashflow']);
        Route::get('/report/global',[ReportController::class,'global']);
        Route::get('/stores/{store}/closings',[ClosingController::class,'index']);
        Route::post('/stores/{store}/closings',[ClosingController::class,'close']);
    });
});

Route::get('/{any?}', function () {
    $path = public_path('app/index.html');
    if (!file_exists($path)) return response('Frontend belum dibuild. Jalankan npm install && npm run build di folder frontend.', 503);
    return response()->file($path);
})->where('any','^(?!api(?:/|$)).*');
