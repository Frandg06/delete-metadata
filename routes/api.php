<?php

use App\Http\Controllers\ImageMetadataController;
use Illuminate\Support\Facades\Route;

Route::post('/images/strip-metadata', [ImageMetadataController::class, 'stripMetadata']);
