<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ExtendSessionLifetimeWhenRemembered
{
    /**
     * Duración de la sesión (en minutos) cuando se marca "recordarme".
     */
    protected const REMEMBERED_LIFETIME_MINUTES = 60 * 24 * 30;

    /**
     * Debe ejecutarse antes de que Sanctum arranque la sesión
     * (EnsureFrontendRequestsAreStateful), para que StartSession lea ya el
     * lifetime extendido al construirse.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('post') && $request->is('api/v1/auth/login') && $request->boolean('remember')) {
            config(['session.lifetime' => self::REMEMBERED_LIFETIME_MINUTES]);
        }

        return $next($request);
    }
}
