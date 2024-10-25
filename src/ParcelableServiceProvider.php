<?php

namespace Ondrejsanetrnik\Parcelable;

use Illuminate\Support\ServiceProvider;

class ParcelableServiceProvider extends ServiceProvider
{
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../config/parcelable.php' => config_path('parcelable.php'),
        ], 'config');

        $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'parcelable');
    }

    public function register()
    {
    }
}
