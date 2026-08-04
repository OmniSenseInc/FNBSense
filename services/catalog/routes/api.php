<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\PromotionEvaluationController;
use App\Http\Controllers\RecipeController;
use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'catalog', 'status' => 'ok']));

// Menu publik (customer & Ordering) — tenant via ?tenant=<uuid>.
// Publik tanpa auth -> throttle per-IP cegah scraping/abuse (SECURITY_TODO).
Route::get('menu', [MenuController::class, 'show'])->middleware('throttle:60,1');

// Resep batch untuk Inventory (service-to-service, auth X-Service-Token).
// ?tenant=<uuid>&products=<uuid,uuid,...>
Route::get('recipe', [RecipeController::class, 'batch'])->middleware(['service', 'throttle:120,1']);

// Nama bahan batch untuk Inventory (service-to-service, auth X-Service-Token).
// ?tenant=<uuid>&ids=<uuid,uuid,...>
//
// Singular `ingredient`, sepola `recipe` di atas: bentuk singular menandai
// endpoint mesin, bentuk jamak (`ingredients` di grup owner) menandai CRUD
// manusia. Dua alamat berbeda karena dua pembacanya berbeda — kasir lewat
// Inventory cuma boleh melihat nama, owner boleh melihat segalanya.
Route::get('ingredient', [IngredientController::class, 'batch'])->middleware(['service', 'throttle:120,1']);
Route::post('internal/promotions/evaluate', PromotionEvaluationController::class)->middleware(['service', 'throttle:120,1']);

// Manajemen menu — owner saja (JWT terverifikasi + role:owner).
Route::middleware(['jwt', 'role:owner'])->group(function () {
    Route::get('categories', [CategoryController::class, 'index']);
    Route::post('categories', [CategoryController::class, 'store']);
    Route::put('categories/{id}', [CategoryController::class, 'update']);
    Route::delete('categories/{id}', [CategoryController::class, 'destroy']);

    Route::get('products', [ProductController::class, 'index']);
    Route::post('products', [ProductController::class, 'store']);
    Route::put('products/{id}', [ProductController::class, 'update']);
    Route::delete('products/{id}', [ProductController::class, 'destroy']);

    Route::get('ingredients', [IngredientController::class, 'index']);
    Route::post('ingredients', [IngredientController::class, 'store']);
    Route::put('ingredients/{id}', [IngredientController::class, 'update']);
    Route::delete('ingredients/{id}', [IngredientController::class, 'destroy']);

    Route::get('recipes', [RecipeController::class, 'index']);
    Route::post('recipes', [RecipeController::class, 'store']);
    Route::put('recipes/{id}', [RecipeController::class, 'update']);
    Route::delete('recipes/{id}', [RecipeController::class, 'destroy']);

    Route::get('promotion-templates', [PromotionController::class, 'templates']);
    Route::get('promotions', [PromotionController::class, 'index']);
    Route::post('promotions', [PromotionController::class, 'store']);
    Route::get('promotions/{id}', [PromotionController::class, 'show']);
    Route::put('promotions/{id}', [PromotionController::class, 'update']);
    Route::post('promotions/{id}/activate', [PromotionController::class, 'activate']);
    Route::post('promotions/{id}/pause', [PromotionController::class, 'pause']);
    Route::delete('promotions/{id}', [PromotionController::class, 'destroy']);
});
