<?php

namespace App\Providers;

use App\Services\ConfiguracionService;
use App\Services\SatDescripciones;
use App\View\Composers\EmisorComposer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\View;
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

        // Igual que el anterior: memoiza las descripciones del catálogo SAT durante la generación
        // de un PDF (ver 019-formato-pdf-documentos.md).
        $this->app->singleton(SatDescripciones::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // El emisor entra por composer y no por los controladores: los PDF salen por seis caminos
        // distintos, incluidas las rutas públicas firmadas que no tienen usuario autenticado
        // (ver 019-formato-pdf-documentos.md).
        View::composer('pdf.*', EmisorComposer::class);

        ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            $email = urlencode($notifiable->getEmailForPasswordReset());

            return config('app.frontend_url')."/reset-password?token={$token}&email={$email}";
        });
    }
}
