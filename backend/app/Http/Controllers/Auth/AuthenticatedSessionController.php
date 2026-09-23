<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    /**
     * Cuánto dura "recordarme" (en minutos). Laravel recuerda ~400 días por
     * defecto; 30 días es lo que la historia le promete al usuario (ver
     * specs/002-login-auth.md y specs/044-sesion-caida-y-pantallas-trabadas.md).
     */
    protected const REMEMBER_DURATION_MINUTES = 60 * 24 * 30;

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): Response
    {
        // Antes de autenticar: Auth::attempt emite la cookie recaller en el momento,
        // con la duración que el guard tenga fijada en ese instante.
        Auth::guard('web')->setRememberDuration(self::REMEMBER_DURATION_MINUTES);

        $request->authenticate();

        $request->session()->regenerate();

        return response()->noContent();
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
