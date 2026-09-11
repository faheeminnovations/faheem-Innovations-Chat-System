<?php

use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Web\AuthController;
use App\Http\Controllers\Web\ChatController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Faheem Innovations - Chat Web Routes
|--------------------------------------------------------------------------
| Serves the Blade UI and its AJAX endpoints under /app/* using normal
| session auth + CSRF (same controllers as the token-based /api/* routes
| in routes/api.php, reused here so there's no duplicated business logic).
*/

Route::get('/', fn () => redirect()->route(auth()->check() ? 'chat.index' : 'login'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/chat', [ChatController::class, 'index'])->name('chat.index');
    Route::get('/chat/{conversation}', [ChatController::class, 'index'])->name('chat.show');

    // AJAX endpoints used by public/js/chat.js (session + CSRF, not tokens)
    Route::prefix('app')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::post('/heartbeat', [UserController::class, 'heartbeat']);

        Route::get('/conversations', [ConversationController::class, 'index']);
        Route::post('/conversations', [ConversationController::class, 'store']);
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show']);
        Route::post('/conversations/{conversation}/participants', [ConversationController::class, 'addParticipants']);
        Route::delete('/conversations/{conversation}/leave', [ConversationController::class, 'leave']);
        Route::delete('/conversations/{conversation}', [ConversationController::class, 'destroy']);

        Route::get('/conversations/{conversation}/messages', [MessageController::class, 'index']);
        Route::post('/conversations/{conversation}/messages', [MessageController::class, 'store']);
        Route::post('/conversations/{conversation}/messages/read', [MessageController::class, 'markRead']);
        Route::delete('/messages/{message}', [MessageController::class, 'destroy']);

        Route::post('/conversations/{conversation}/typing', [MessageController::class, 'typing']);
        Route::get('/conversations/{conversation}/typing', [MessageController::class, 'typingUsers']);
    });
});
