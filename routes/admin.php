<?php

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\TeamController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

// Admin only. The middleware is the protection; the hidden sidebar link is only convenience.
Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('settings/{section?}', [SettingsController::class, 'edit'])
        ->whereIn('section', SettingsController::SECTIONS)->name('settings');
    Route::put('settings/{section}', [SettingsController::class, 'update'])
        ->whereIn('section', SettingsController::SECTIONS)->name('settings.update');

    // Connection tests call outside services, so they are rate limited.
    Route::post('settings/ai/test', [SettingsController::class, 'testAi'])->middleware('throttle:10,1')->name('settings.ai.test');
    Route::post('settings/mail/test', [SettingsController::class, 'testMail'])->middleware('throttle:10,1')->name('settings.mail.test');

    Route::model('member', User::class);
    Route::get('team', [TeamController::class, 'index'])->name('team');
    Route::post('team', [TeamController::class, 'store'])->name('team.store');
    Route::get('team/{member}', [TeamController::class, 'edit'])->name('team.edit');
    Route::put('team/{member}', [TeamController::class, 'update'])->name('team.update');
    Route::post('team/{member}/reset-password', [TeamController::class, 'resetPassword'])
        ->middleware('throttle:10,1')->name('team.reset-password');
});
