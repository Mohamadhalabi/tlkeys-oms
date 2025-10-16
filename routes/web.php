<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\OrderPdfController;


Route::get('/', function () {
    return view('landing');   // resources/views/landing.blade.php
})->name('landing');


Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::get('/admin/set-locale/{locale}', function (string $locale) {
    abort_unless(in_array($locale, ['en', 'ar'], true), 404);
    session(['locale' => $locale]);   // middleware will read this on next request
    return back();
})->middleware(['web', 'auth'])->name('admin.set-locale');


Route::post('/admin/products/import', \App\Http\Controllers\ProductImportController::class)
    ->middleware(['auth'])
    ->name('products.import');


Route::middleware(['web','auth'])->group(function () {
    Route::get('/admin/orders/{order}/pdf', [OrderPdfController::class, 'show'])
        ->name('admin.orders.pdf');
});

Route::get('/seller/set-locale/{locale}', function (string $locale) {
    // allow only your supported locales
    if (! in_array($locale, ['en','ar'])) {
        $locale = 'en';
    }
    session(['locale' => $locale]);
    app()->setLocale($locale);
    return back();
})->name('seller.set-locale');

require __DIR__.'/auth.php';
