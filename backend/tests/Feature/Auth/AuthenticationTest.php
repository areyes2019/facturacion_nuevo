<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Sanctum arranca la sesión con un pipeline propio dentro de
 * EnsureFrontendRequestsAreStateful; el guard 'web' queda cacheado en el
 * AuthManager con la sesión de la petición anterior. En una petición HTTP
 * real esto no pasa (cada request es un proceso/contenedor nuevo), pero
 * dentro de un mismo test hay que forzar a Laravel a resolver el guard de
 * nuevo para comprobar el estado real de la petición siguiente.
 *
 * Olvidar la instancia del contenedor no basta: las fachadas guardan su propia
 * copia resuelta, y middleware como `guest` consultan `Auth::` a través de ella.
 */
function olvidarSesionCacheada(): void
{
    app()->forgetInstance('auth');
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');
    Facade::clearResolvedInstances();
}

/**
 * La cookie recaller nativa de Laravel, la que hace durar "recordarme".
 *
 * Se devuelve **descifrada**: `withCookie()` cifra lo que se le pasa, así que
 * reenviar el valor tal como viaja en la respuesta lo dejaría cifrado dos veces
 * y EncryptCookies lo descartaría en silencio.
 */
function cookieDeRecuerdo(TestResponse $respuesta): ?Cookie
{
    foreach ($respuesta->headers->getCookies() as $cookie) {
        if (str_starts_with($cookie->getName(), 'remember_web_')) {
            return $respuesta->getCookie($cookie->getName());
        }
    }

    return null;
}

/**
 * Lo que le pasa a una sesión al caducar por inactividad: con
 * SESSION_DRIVER=database, que su renglón deje de estar disponible. El
 * DatabaseSessionHandler decide la expiración contra config('session.lifetime')
 * en *cada* petición (ver specs/044-sesion-caida-y-pantallas-trabadas.md).
 */
function expirarSesionDelServidor(): void
{
    DB::table('sessions')->delete();
    olvidarSesionCacheada();
}

test('users can authenticate using the login screen', function () {
    $user = User::factory()->create();

    $response = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertNoContent();
});

test('users can not authenticate with invalid password', function () {
    $user = User::factory()->create();

    $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

test('users can logout', function () {
    $user = User::factory()->create();

    // Login real (no actingAs) para dejar la sesión stateful de Sanctum en el
    // mismo estado que tendría un navegador real antes de hacer logout.
    $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response = $this->post('/api/v1/auth/logout');
    $response->assertNoContent();

    olvidarSesionCacheada();

    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('recordarme revive la sesion cuando la del servidor ya expiro', function () {
    $user = User::factory()->create();

    $login = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $login->assertNoContent();

    $recuerdo = cookieDeRecuerdo($login);
    expect($recuerdo)->not->toBeNull();

    expirarSesionDelServidor();

    // Es lo que hace un navegador al día siguiente: la cookie de sesión ya no
    // vale nada, pero la de recuerdo sigue ahí y basta para volver a entrar.
    $this->withCredentials()->withCookie($recuerdo->getName(), $recuerdo->getValue())
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('sin recordarme la sesion expirada no revive', function () {
    $user = User::factory()->create();

    $login = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => false,
    ]);

    $login->assertNoContent();
    expect(cookieDeRecuerdo($login))->toBeNull();

    expirarSesionDelServidor();

    $this->getJson('/api/v1/user')->assertUnauthorized();
});

test('el recuerdo dura 30 dias, no los ~400 por defecto de Laravel', function () {
    $user = User::factory()->create();

    $login = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $dias = (cookieDeRecuerdo($login)->getExpiresTime() - time()) / 86400;

    expect($dias)->toBeGreaterThan(29.5)->toBeLessThan(30.5);
});

test('cerrar sesion invalida tambien el recuerdo', function () {
    $user = User::factory()->create();

    $login = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $recuerdo = cookieDeRecuerdo($login);

    $this->post('/api/v1/auth/logout')->assertNoContent();

    expirarSesionDelServidor();

    // El logout rota users.remember_token, así que la cookie que el navegador
    // todavía tenga guardada deja de servir para entrar.
    $this->withCredentials()->withCookie($recuerdo->getName(), $recuerdo->getValue())
        ->getJson('/api/v1/user')
        ->assertUnauthorized();
});

test('cambiar la contrasena invalida el recuerdo de otros aparatos', function () {
    $user = User::factory()->create();

    $login = $this->post('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'password',
        'remember' => true,
    ]);

    $recuerdo = cookieDeRecuerdo($login);
    $token = Password::createToken($user);

    // Se restablece desde otro aparato, sin sesión: la ruta pide 'guest'.
    expirarSesionDelServidor();

    $this->post('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'contrasena-nueva',
        'password_confirmation' => 'contrasena-nueva',
    ])->assertOk();

    olvidarSesionCacheada();

    $this->withCredentials()->withCookie($recuerdo->getName(), $recuerdo->getValue())
        ->getJson('/api/v1/user')
        ->assertUnauthorized();
});
