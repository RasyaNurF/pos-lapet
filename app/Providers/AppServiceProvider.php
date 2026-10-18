<?php

namespace App\Providers;

use App\Services\SettingService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * Singleton: seluruh request berbagi SATU cache in-memory.
         * Bukan singleton, tiap resolve mengulang query — dan view memanggil
         * pengaturan di dalam loop.
         */
        $this->app->singleton(SettingService::class, fn (): SettingService => new SettingService);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
