<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BlockEventController;
use App\Http\Controllers\Api\ClassRoomController;
use App\Http\Controllers\Api\ClassSessionController;
use App\Http\Controllers\Api\PolicyController;
use Illuminate\Support\Facades\Route;

// Public -- the one network call the app makes before everything else goes
// local (ARCHITECTURE.md Ch.4). Throttled harder than the rest of the API:
// once this is on the public internet, these are exactly the endpoints
// bots hit for spam registrations and PIN/password brute-forcing.
Route::middleware('throttle:6,1')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Google sign-in. Looser than password login since there is nothing to
// brute-force (the ID token is signed by Google). The limit is per IP, so a
// class signing in together from one school network shares it.
Route::middleware('throttle:20,1')->post('/auth/google', [AuthController::class, 'google']);

// Everything else requires the Sanctum token cached on first login.
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    Route::get('/classes', [ClassRoomController::class, 'index']);
    Route::post('/classes', [ClassRoomController::class, 'store']);
    Route::post('/classes/join', [ClassRoomController::class, 'join']);
    Route::get('/classes/{class}', [ClassRoomController::class, 'show']);

    Route::get('/classes/{class}/policies', [PolicyController::class, 'index']);
    Route::post('/classes/{class}/policies', [PolicyController::class, 'store']);

    Route::post('/classes/{class}/sessions', [ClassSessionController::class, 'store']);
    Route::get('/classes/{class}/sessions/active', [ClassSessionController::class, 'active']);
    Route::post('/sessions/{session}/end', [ClassSessionController::class, 'end']);
    Route::post('/sessions/{session}/block-events', [BlockEventController::class, 'store']);

    // The app's Admin screens -- only the ADMIN_EMAILS in .env.
    Route::middleware('admin')->prefix('admin')->group(function () {
        Route::get('/teachers', [AdminController::class, 'teachers']);
        Route::post('/teachers', [AdminController::class, 'addTeachers']);
        Route::delete('/teachers/{email}', [AdminController::class, 'removeTeacher']);

        Route::get('/classes', [AdminController::class, 'classes']);
        Route::get('/classes/{class}/students', [AdminController::class, 'students']);
        Route::post('/classes/{class}/students', [AdminController::class, 'enroll']);
        Route::delete('/classes/{class}/students/{student}', [AdminController::class, 'unenroll']);
    });
});
