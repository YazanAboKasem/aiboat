<?php

use App\Http\Controllers\AskController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\VectorStoreController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MetaWebhookController;
use App\Http\Controllers\FallbackController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\QuestionController;
use App\Http\Controllers\MessagesController;

Route::get('/ask', function () {
    return view('welcome');
})->name('ask');

Route::post('/api/ask', [AskController::class, 'ask'])->name('api.ask');

// طرق محمية - تتطلب المصادقة
Route::middleware('auth')->group(function () {
    // تسجيل الخروج
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // الصفحة الرئيسية ولوحة التحكم
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard2');

    // إدارة المساعد الثاني
    Route::get('/dashboard/second-assistant', [DashboardController::class, 'manageSecondAssistant'])->name('second.assistant');
    Route::post('/dashboard/second-assistant/update', [DashboardController::class, 'updateSecondAssistant'])->name('second.assistant.update');

    // إدارة إعدادات الذكاء الاصطناعي
    Route::get('/dashboard/ai-settings', [DashboardController::class, 'manageAISettings'])->name('ai.settings');
    Route::post('/dashboard/ai-settings/update', [DashboardController::class, 'updateAISettings'])->name('ai.settings.update');

    // إدارة الأسئلة والأجوبة
    Route::resource('questions', QuestionController::class);

    // نظام الرسائل
    Route::prefix('messages')->name('messages.')->group(function () {

        Route::get('/ask', function () {
            return view('welcome');
        })->name('ask');


        Route::get('/', [MessagesController::class, 'index'])->name('index');
        Route::get('/{senderId}', [MessagesController::class, 'show'])->name('show');
        Route::post('/{senderId}/reply', [MessagesController::class, 'reply'])->name('reply');
    });

    Route::post('/assistant/create', [AssistantController::class, 'createAssistant'])->name('assistant_create');
    Route::post('/vector-store/setup', [VectorStoreController::class, 'setup'])->name('vector_setup');
    Route::post('/vector-store/status', [VectorStoreController::class, 'checkStatus'])->name('vector_status');
    Route::post('/assistant/ask', [AssistantController::class, 'ask'])->name('assistant_ask');
});
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login'])->name('login2');

// Test route to verify routing is working
//Route::get('/test', function () {
//    return 'Basic routing test successful!';
//});

// Also add webhook routes here to ensure they're accessible


// Add a fallback route to catch all unmatched routes

