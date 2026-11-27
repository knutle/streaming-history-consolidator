<?php

use App\Http\Controllers\AlbumArtworkController;
use App\Http\Controllers\BulkMusicBrainzController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ListenController;
use App\Http\Controllers\ManualMatchController;
use App\Http\Controllers\MusicEntityController;
use App\Http\Controllers\PendingMusicBrainzMatchController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('artwork/{mbid}', [AlbumArtworkController::class, 'image'])->name('artwork.image');
    Route::post('listens/{listen}/artwork/search', [AlbumArtworkController::class, 'search'])->middleware('throttle:30,1')->name('artwork.search');
    Route::post('listens/{listen}/artwork', [AlbumArtworkController::class, 'store'])->middleware('throttle:30,1')->name('artwork.store');
    Route::delete('listens/{listen}/artwork', [AlbumArtworkController::class, 'destroy'])->name('artwork.destroy');
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('listens', [ListenController::class, 'index'])->name('listens.index');
    Route::post('listens/bulk-musicbrainz/selection', [BulkMusicBrainzController::class, 'selection'])->name('listens.bulk-musicbrainz.selection');
    Route::post('listens/bulk-musicbrainz', [BulkMusicBrainzController::class, 'store'])->name('listens.bulk-musicbrainz.store');
    Route::post('listens/bulk-musicbrainz/{run}/cancel', [BulkMusicBrainzController::class, 'cancel'])->name('listens.bulk-musicbrainz.cancel');
    Route::post('listens/bulk-musicbrainz/{run}/resume', [BulkMusicBrainzController::class, 'resume'])->name('listens.bulk-musicbrainz.resume');
    Route::get('listens/bulk-musicbrainz/review', [PendingMusicBrainzMatchController::class, 'index'])->name('listens.bulk-musicbrainz.review');
    Route::post('listens/bulk-musicbrainz/review/{pending}', [PendingMusicBrainzMatchController::class, 'approve'])->name('listens.bulk-musicbrainz.approve');
    Route::delete('listens/bulk-musicbrainz/review/{pending}', [PendingMusicBrainzMatchController::class, 'destroy'])->name('listens.bulk-musicbrainz.dismiss');
    Route::post('listens/import', [ListenController::class, 'store'])->name('listens.import');
    Route::post('imports', [ImportController::class, 'store'])->name('imports.store');
    Route::post('imports/{import}/retry', [ImportController::class, 'retry'])->name('imports.retry');
    Route::get('listens/{listen}', [ListenController::class, 'show'])->name('listens.show');
    Route::post('listens/{listen}/match', [ListenController::class, 'match'])->name('listens.match');
    Route::post('listens/{listen}/catalog', [ListenController::class, 'matchCatalog'])->name('listens.catalog');
    Route::post('listens/{listen}/merge', [ListenController::class, 'merge'])->name('listens.merge');
    Route::delete('listens/{listen}/merge/{member}', [ListenController::class, 'unlink'])->name('listens.unlink');
    Route::post('listens/{listen}/spotify-lookup', [ListenController::class, 'lookupSpotify'])->name('listens.spotify-lookup');
    Route::get('matches', [ManualMatchController::class, 'index'])->name('matches.index');
    Route::get('matches/recording/{match}/edit', [ManualMatchController::class, 'edit'])->name('matches.edit');
    Route::put('matches/recording/{match}', [ManualMatchController::class, 'update'])->name('matches.update');
    Route::delete('matches/{type}/{match}', [ManualMatchController::class, 'destroy'])->name('matches.destroy');
    Route::get('artists', [MusicEntityController::class, 'artists'])->name('artists.index');
    Route::get('albums', [MusicEntityController::class, 'albums'])->name('albums.index');
    Route::get('music-entities/{type}/{listen}', [MusicEntityController::class, 'manage'])->name('music-entities.manage');
    Route::post('music-entities/{type}/{listen}/musicbrainz', [MusicEntityController::class, 'matchMusicBrainz'])->name('music-entities.musicbrainz');
    Route::post('music-entities/{type}/{listen}/catalog', [MusicEntityController::class, 'matchCatalog'])->name('music-entities.catalog');
    Route::delete('music-entities/{type}/{listen}/{kind}', [MusicEntityController::class, 'removeMatch'])->name('music-entities.remove');
    Route::get('artists/{mbid}', [MusicEntityController::class, 'artist'])->name('artists.show');
    Route::post('artists/{mbid}/lookup-name', [MusicEntityController::class, 'lookupArtistName'])->name('artists.lookup-name');
    Route::get('albums/{mbid}', [MusicEntityController::class, 'album'])->name('albums.show');
});

Route::middleware('auth')->get('imports', [ImportController::class, 'index'])->name('imports.index');
Route::middleware('auth')->post('imports/bulk/abort', [ImportController::class, 'abortAll'])->name('imports.bulk.abort');
Route::middleware('auth')->delete('imports/bulk', [ImportController::class, 'destroyFinished'])->name('imports.bulk.destroy');
Route::middleware('auth')->post('imports/{import}/abort', [ImportController::class, 'abort'])->name('imports.abort');
Route::middleware('auth')->delete('imports/{import}/files', [ImportController::class, 'deleteFiles'])->name('imports.files.destroy');

require __DIR__.'/settings.php';
