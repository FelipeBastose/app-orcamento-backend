<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\CategoryController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Rotas de autenticação (públicas)
Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

// Rotas protegidas por autenticação
Route::middleware('auth:sanctum')->group(function () {
    // Autenticação
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    
    // Categorias
    Route::apiResource('categories', CategoryController::class);
    
    // Transações
    Route::prefix('transactions')->group(function () {
        Route::get('/', [TransactionController::class, 'index']);
        Route::get('/{id}', [TransactionController::class, 'show']);
        Route::post('/upload-csv', [TransactionController::class, 'uploadCSV']);
        Route::put('/{id}/category', [TransactionController::class, 'updateCategory']);
        Route::post('/recategorize-ai', [TransactionController::class, 'recategorizeWithAI']);
        Route::get('/ai-stats', [TransactionController::class, 'getAIStats']);
    });
    
    // Dashboard
    Route::prefix('dashboard')->group(function () {
        Route::get('/', [DashboardController::class, 'index']);
        Route::post('/filter-period', [DashboardController::class, 'filterByPeriod']);
        Route::post('/filter-month', [DashboardController::class, 'filterByMonth']);
    });

    // Rota para obter dados do usuário
    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
