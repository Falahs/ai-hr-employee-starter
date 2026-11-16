<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\MailboxController;
use Illuminate\Support\Facades\Route;

// Team pages. Each action checks its own permission (Gate) in the controller.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('mail', [MailboxController::class, 'index'])->name('mail');
    Route::get('mail/compose', [MailboxController::class, 'compose'])->name('mail.compose');
    Route::post('mail', [MailboxController::class, 'send'])->middleware('throttle:20,1')->name('mail.send');
    Route::get('mail/{email}', [MailboxController::class, 'show'])->name('mail.show');
    Route::post('mail/{email}/retry', [MailboxController::class, 'retry'])->name('mail.retry');
    Route::get('attachments/{attachment}', [MailboxController::class, 'attachment'])->name('mail.attachment');

    Route::get('chat', [ChatController::class, 'index'])->name('chat');
    Route::post('chat', [ChatController::class, 'store'])->name('chat.store');
    Route::get('chat/{session}', [ChatController::class, 'index'])->name('chat.show');
    // each message is a model call: limited per user
    Route::post('chat/{session}', [ChatController::class, 'send'])->middleware('throttle:20,1')->name('chat.send');
    Route::put('chat/{session}', [ChatController::class, 'rename'])->name('chat.rename');
    Route::delete('chat/{session}', [ChatController::class, 'destroy'])->name('chat.destroy');
});
