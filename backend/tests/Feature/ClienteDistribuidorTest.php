<?php

use App\Models\Cliente;
use App\Models\User;
use PhpCfdi\Rfc\RfcFaker;

/**
 * Cliente distribuidor (ver 033-precio-distribuidor.md). La selección automática del precio en
 * cotización/factura es un mecanismo del frontend (no hay lógica de negocio en el backend más allá
 * de guardar la marca), así que esta suite solo cubre la ficha del cliente.
 */
function datosClienteDistribuidor(array $overrides = []): array
{
    return array_merge([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Ferretería López SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
    ], $overrides);
}

test('se puede crear un cliente marcado como distribuidor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteDistribuidor([
        'es_distribuidor' => true,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.es_distribuidor', true);
    $this->assertDatabaseHas('clientes', ['id' => $response->json('data.id'), 'es_distribuidor' => true]);
});

test('un cliente creado sin la marca queda como no distribuidor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteDistribuidor());

    $response->assertCreated();
    $response->assertJsonPath('data.es_distribuidor', false);
});

test('un valor en blanco de es_distribuidor equivale a no distribuidor', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create(['es_distribuidor' => true]);

    $response = $this->actingAs($user)->putJson("/api/v1/clientes/{$cliente->id}", datosClienteDistribuidor([
        'rfc' => $cliente->rfc,
        'es_distribuidor' => '',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.es_distribuidor', false);
});

test('se puede marcar o desmarcar a un cliente existente como distribuidor', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create(['es_distribuidor' => false]);

    $this->actingAs($user)->putJson("/api/v1/clientes/{$cliente->id}", datosClienteDistribuidor([
        'rfc' => $cliente->rfc,
        'es_distribuidor' => true,
    ]))->assertOk()->assertJsonPath('data.es_distribuidor', true);

    expect((bool) $cliente->fresh()->es_distribuidor)->toBeTrue();
});

test('la marca de distribuidor de un cliente no es modificable por otro usuario', function () {
    $dueno = User::factory()->create();
    $ajeno = User::factory()->create();
    $cliente = Cliente::factory()->for($dueno)->create(['es_distribuidor' => false]);

    $this->actingAs($ajeno)->putJson("/api/v1/clientes/{$cliente->id}", datosClienteDistribuidor([
        'rfc' => $cliente->rfc,
        'es_distribuidor' => true,
    ]))->assertNotFound();

    expect((bool) $cliente->fresh()->es_distribuidor)->toBeFalse();
});
