<?php

use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\ScanController;
use App\Http\Middleware\RequireApiKey;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireApiKey::class)->group(function () {
    Route::post('/scans', [ScanController::class, 'store']);
    Route::post('/scans/{scanId}/images', [ScanController::class, 'storeImage'])->whereUuid('scanId');
    Route::post('/scans/{scanId}/preprocess-bg', [ScanController::class, 'preprocess'])->whereUuid('scanId');
    Route::post('/scans/{scanId}/submit', [ScanController::class, 'submit'])->whereUuid('scanId');
    Route::get('/scans/{scanId}', [ScanController::class, 'show'])->whereUuid('scanId');

    Route::get('/jobs/{jobId}', [JobController::class, 'show'])->whereUuid('jobId');

    Route::get('/scans/{scanId}/images/{slot}/rgba', [FileController::class, 'rgba'])
        ->whereUuid('scanId')
        ->whereNumber('slot')
        ->name('api.scans.images.rgba');

    Route::get('/files/{scanId}/{type}', [FileController::class, 'show'])
        ->whereUuid('scanId')
        ->whereIn('type', ['glb', 'usdz'])
        ->name('api.files.show');
});
