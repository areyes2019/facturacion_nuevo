<?php

namespace App\Providers;

use App\Services\ConfiguracionService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\ServiceProvider;
use PhpCfdi\SatCatalogos\Factory as SatCatalogosFactory;
use PhpCfdi\SatCatalogos\SatCatalogos;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SatCatalogos::class, function () {
            $dsn = 'sqlite:'.config('services.sat_catalogos.database_path');

            return (new SatCatalogosFactory)->catalogosFromDsn($dsn);
        });

        // Singleton para que la memoización de los ajustes del usuario dure toda la petición
        // (ver 014-costo-elaboracion-goma.md).
        $this->app->singleton(ConfiguracionService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return config('app.frontend_url')."/reset-password?token={$token}&email={$email}";
        });
    }
}
