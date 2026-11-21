<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MemoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/login', 'login')->name('login');
    Route::post('/login', [AccountController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/invite/{token}', [AccountController::class, 'showInvitation'])
        ->where('token', '[A-Za-z0-9]{64}')->name('invitation.show');
    Route::post('/invite/{token}', [AccountController::class, 'redeem'])
        ->where('token', '[A-Za-z0-9]{64}')->middleware('throttle:10,1')->name('invitation.redeem');
});

Route::middleware('auth')->group(function () {
    Route::get('/imports', [MailboxController::class, 'index'])->name('imports.index');
    Route::post('/imports', [MailboxController::class, 'store'])->name('imports.store');
    Route::get('/imports/{batch}', [MailboxController::class, 'show'])->whereUuid('batch')->name('imports.show');
    Route::get('/passages/{passage}', [MailboxController::class, 'passage'])->whereNumber('passage')->name('passages.show');
    Route::post('/logout', [AccountController::class, 'logout'])->name('logout');
    Route::get('/', [MemoryController::class, 'index'])->name('memories.index');
    Route::post('/search', [MemoryController::class, 'search'])->name('memories.search');
    Route::post('/memories', [MemoryController::class, 'store'])->name('memories.store');
    Route::get('/memories/{memory}', [MemoryController::class, 'show'])->whereUuid('memory')->name('memories.show');
    Route::patch('/memories/{memory}', [MemoryController::class, 'update'])->whereUuid('memory')->name('memories.update');
});
