<?php

use App\Http\Controllers\ImageMetadataController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('images.strip-metadata');
});

Route::get('/images/download', [ImageMetadataController::class, 'download'])->name('images.download');
Route::post('/images/download-zip', [ImageMetadataController::class, 'downloadZip'])->name('images.download-zip');
