<?php

use App\Http\Controllers\AskController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\FallbackController;
use App\Http\Controllers\QuestionController;

Route::get('/', function () {
    return view('welcome');
});

// Questions management routes
Route::resource('questions', QuestionController::class);

// Test route to verify routing is working
//Route::get('/test', function () {
//    return 'Basic routing test successful!';
//});

// Also add webhook routes here to ensure they're accessible


// Add a fallback route to catch all unmatched routes

