<?php

use App\Http\Controllers\ListenController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::get('listens', [ListenController::class, 'index'])->name('listens.index');
    Route::post('listens/import', [ListenController::class, 'store'])->name('listens.import');
});

require __DIR__.'/settings.php';
