<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductImportController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::post('/imports/products', ProductImportController::class)->name('imports.products');



Route::get('/admin/set-locale/{locale}', function (string $locale) {
    $allowed = ['en','ar','tr','fr','es','de','ru']; // add/remove as you like
    abort_unless(in_array($locale, $allowed, true), 404);

    // Remember in session
    session(['locale' => $locale]);

    // Persist on user too (optional, so they keep their choice after logout)
    if (auth()->check()) {
        auth()->user()->forceFill(['locale' => $locale])->save();
    }

    // Go back to the previous page
    return back();
})->middleware(['web','auth'])->name('admin.set-locale');

require __DIR__.'/auth.php';
