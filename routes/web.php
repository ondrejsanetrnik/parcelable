<?php

use Illuminate\Support\Facades\Route;
use Ondrejsanetrnik\Parcelable\ParcelController;

# Labels carry recipient names and addresses, so these routes must never be public.
# Apps can narrow access further (e.g. admins only) via parcelable.route_middleware.
Route::middleware(config('parcelable.route_middleware', ['web', 'auth']))->group(function () {
    Route::get('/parcels/{id}/label', [ParcelController::class, 'label'])->name('label');
    Route::get('/parcels', [ParcelController::class, 'index'])->name('parcelsIndex');
    Route::post('/parcels/{id}/remove', [ParcelController::class, 'remove'])->name('parcelsRemove');
});
