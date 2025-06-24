<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TicketController;

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

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::prefix('ticket')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    # 搶票 API
    Route::post('grab/{ticket}', [TicketController::class, 'grabTicket']);
    # 查詢庫存 API
    Route::get('stock/{ticket}', [TicketController::class, 'getTicketStock']);
});

