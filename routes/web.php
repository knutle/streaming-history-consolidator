<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ListenController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('listens', [ListenController::class, 'index'])->name('listens.index');
    Route::post('listens/import', [ListenController::class, 'store'])->name('listens.import');
    Route::get('listens/{listen}', [ListenController::class, 'show'])->name('listens.show');
    Route::post('listens/{listen}/match', [ListenController::class, 'match'])->name('listens.match');
});

require __DIR__.'/settings.php';
