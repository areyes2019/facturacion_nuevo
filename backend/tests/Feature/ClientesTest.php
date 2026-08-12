<?php

use App\Models\Cliente;
use App\Models\User;
use PhpCfdi\Rfc\RfcFaker;

function datosClienteValidos(array $overrides = []): array
{
    return array_merge([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'nombre_comercial' => 'Ejemplo',
        'correo_contacto' => 'contacto@ejemplo.test',
        'telefono' => '4491234567',
        'direccion_comercial' => 'Av. Siempre Viva 123',
    ], $overrides);
}

test('un invitado no puede acceder a clientes', function () {
    $this->getJson('/api/v1/clientes')->assertUnauthorized();
});

test('un usuario autenticado puede crear un cliente con datos fiscales y comerciales', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos());

    $response->assertCreated();
    $response->assertJsonPath('data.razon_social', 'Comercializadora Ejemplo SA de CV');
    $response->assertJsonPath('data.regimen_fiscal', '601');
    $response->assertJsonPath('data.tipo_persona', 'moral');
    $this->assertDatabaseHas('clientes', [
        'user_id' => $user->id,
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
    ]);
});

test('un rfc con formato invalido no permite crear el cliente', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos([
        'rfc' => 'NO-ES-UN-RFC',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('rfc');
});

test('se acepta el rfc generico de publico en general', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos([
        'rfc' => 'XAXX010101000',
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.tipo_persona', null);
});

test('un rfc duplicado para el mismo usuario es rechazado', function () {
    $user = User::factory()->create();
    Cliente::factory()->for($user)->create(['rfc' => 'AAA010101AAA']);

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos([
        'rfc' => 'AAA010101AAA',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('rfc');
});

test('el mismo rfc si puede registrarse por dos usuarios distintos', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    Cliente::factory()->for($user1)->create(['rfc' => 'AAA010101AAA']);

    $response = $this->actingAs($user2)->postJson('/api/v1/clientes', datosClienteValidos([
        'rfc' => 'AAA010101AAA',
    ]));

    $response->assertCreated();
});

test('un regimen fiscal fuera del catalogo sat es rechazado', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos([
        'regimen_fiscal' => '999',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('regimen_fiscal');
});

test('un codigo postal que no existe en el catalogo sat es rechazado', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/clientes', datosClienteValidos([
        'codigo_postal_fiscal' => '00001',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('codigo_postal_fiscal');
});

test('el listado solo muestra los clientes del usuario autenticado y permite buscar', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    Cliente::factory()->for($user)->create(['razon_social' => 'Panaderia La Espiga']);
    Cliente::factory()->for($user)->create(['razon_social' => 'Ferreteria El Tornillo']);
    Cliente::factory()->for($otro)->create(['razon_social' => 'Cliente de Otro Usuario']);

    $response = $this->actingAs($user)->getJson('/api/v1/clientes');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $busqueda = $this->actingAs($user)->getJson('/api/v1/clientes?search=Espiga');
    $busqueda->assertOk();
    expect($busqueda->json('data'))->toHaveCount(1);
    $busqueda->assertJsonPath('data.0.razon_social', 'Panaderia La Espiga');
});

test('un usuario no puede ver el cliente de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $cliente = Cliente::factory()->for($otro)->create();

    $this->actingAs($user)->getJson("/api/v1/clientes/{$cliente->id}")->assertNotFound();
});

test('editar un cliente existente persiste los cambios', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/clientes/{$cliente->id}", datosClienteValidos([
        'rfc' => $cliente->rfc,
        'razon_social' => 'Razón Social Actualizada',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.razon_social', 'Razón Social Actualizada');
    $this->assertDatabaseHas('clientes', [
        'id' => $cliente->id,
        'razon_social' => 'Razón Social Actualizada',
    ]);
});

test('eliminar un cliente lo remueve del listado pero no lo borra fisicamente (soft delete)', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/clientes/{$cliente->id}")->assertNoContent();

    $this->actingAs($user)->getJson('/api/v1/clientes')->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('clientes', ['id' => $cliente->id]);
});

test('el catalogo de regimenes fiscales se puede consultar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/regimenes-fiscales');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
    $response->assertJsonFragment(['id' => '601', 'texto' => 'General de Ley Personas Morales']);
});

test('el catalogo de codigos postales se puede buscar por prefijo', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/codigos-postales?q=2000');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});
