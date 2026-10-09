<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageFinderController;

Route::get('/', [ImageFinderController::class, 'index'])->name('finder.index');
Route::post('/search', [ImageFinderController::class, 'search'])->name('finder.search');
// Google Drive IDs are not SHA1 hashes; allow Drive file ID characters.
Route::get('/catalog/{id}', [ImageFinderController::class, 'image'])
    ->where('id', '[A-Za-z0-9_-]{8,200}')->name('finder.image');
