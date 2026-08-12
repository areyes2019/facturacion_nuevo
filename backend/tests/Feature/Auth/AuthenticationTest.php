<?php

use App\Models\User;

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

    // Sanctum arranca la sesión con un pipeline propio dentro de
    // EnsureFrontendRequestsAreStateful; el guard 'web' queda cacheado en el
    // AuthManager con la sesión de la petición anterior. En una petición HTTP
    // real esto no pasa (cada request es un proceso/contenedor nuevo), pero
    // dentro de un mismo test hay que forzar a Laravel a resolver el guard de
    // nuevo para comprobar el estado real tras el logout.
    $this->app->forgetInstance('auth');
    $this->app->forgetInstance('session');
    $this->app->forgetInstance('session.store');

    $this->getJson('/api/v1/user')->assertUnauthorized();
});
