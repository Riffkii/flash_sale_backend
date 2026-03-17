<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FlashSaleController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/flash-sale/items', [FlashSaleController::class, 'list']);
Route::post('/flash-sale/buy', [FlashSaleController::class, 'buy']);
