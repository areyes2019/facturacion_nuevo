<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Las rutas de auth viven en el grupo 'api' y solo arrancan sesión para
     * peticiones que Sanctum reconoce como venidas del frontend confiable
     * (Origin/Referer en SANCTUM_STATEFUL_DOMAINS). Sin este header, los
     * tests de auth nunca tendrían sesión, igual que un navegador real.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Origin', config('app.frontend_url'));
    }
}
