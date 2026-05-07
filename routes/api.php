<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\CallController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/send-message', [ChatController::class, 'sendMessage']);
Route::post('/call/initiate', [CallController::class, 'initiate']);
Route::post('/call/accept', [CallController::class, 'accept']);
Route::post('/call/reject', [CallController::class, 'reject']);
Route::post('/call/end', [CallController::class, 'end']);
