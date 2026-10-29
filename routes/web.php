<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ImageFinderController;
use App\Http\Controllers\GalleryController;

Route::get('/', [ImageFinderController::class, 'index'])->name('finder.index');
Route::post('/search', [ImageFinderController::class, 'search'])->name('finder.search');
Route::post('/feedback', [ImageFinderController::class, 'feedback'])->name('finder.feedback');
Route::get('/catalog/{id}', [ImageFinderController::class, 'image'])
    ->where('id', '[A-Za-z0-9_-]{8,200}')->name('finder.image');

Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery.index');
Route::get('/gallery/items', [GalleryController::class, 'items'])->name('gallery.items');
