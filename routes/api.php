<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('domains')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\DomainController::class, 'index']);
        Route::get('/{name}', [\App\Http\Controllers\Api\DomainController::class, 'showByName']);
        Route::post('/', [\App\Http\Controllers\Api\DomainController::class, 'create']);
        Route::post('/verify', [\App\Http\Controllers\Api\DomainController::class, 'verify']);
        Route::delete('/{name}', [\App\Http\Controllers\Api\DomainController::class, 'destroy']);
    });

    Route::prefix('servers')->group(function() {
        Route::get('{name}', [\App\Http\Controllers\Api\ServerController::class, 'showByName']);
    });
});
