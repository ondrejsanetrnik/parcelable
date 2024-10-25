<?php

use Illuminate\Support\Facades\Route;
use Ondrejsanetrnik\Parcelable\ParcelController;

Route::get('/parcels/{id}/label', [ParcelController::class, 'label'])->name('label')->middleware('web');
Route::get('/parcels', [ParcelController::class, 'index'])->name('parcelsIndex')->middleware('web');
Route::post('/parcels/{id}/remove', [ParcelController::class, 'remove'])->name('parcelsRemove')->middleware('web');
