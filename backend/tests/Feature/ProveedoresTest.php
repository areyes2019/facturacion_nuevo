<?php

use App\Enums\EstadoOrdenCompra;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use PhpCfdi\Rfc\RfcFaker;

function datosProveedorValidos(array $overrides = []): array
{
    return array_merge([
        'nombre_comercial' => 'Distribuidora Ejemplo SA de CV',
        'nombre_contacto' => 'Juan Pérez',
        'correo' => 'contacto@proveedor.test',
        'telefono' => '4491234567',
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
    ], $overrides);
}

test('un invitado no puede acceder a proveedores', function () {
    $this->getJson('/api/v1/proveedores')->assertUnauthorized();
});

test('un usuario autenticado puede crear un proveedor solo con el nombre comercial', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', [
        'nombre_comercial' => 'Distribuidora Ejemplo SA de CV',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.nombre_comercial', 'Distribuidora Ejemplo SA de CV');
    $response->assertJsonPath('data.tiene_ordenes_activas', false);
    $this->assertDatabaseHas('proveedores', [
        'user_id' => $user->id,
        'nombre_comercial' => 'Distribuidora Ejemplo SA de CV',
    ]);
});

test('omitir el nombre comercial no permite crear el proveedor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'nombre_comercial' => '',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre_comercial');
});

test('un correo con formato invalido no permite crear el proveedor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'correo' => 'no-es-un-correo',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('correo');
});

test('un telefono valido se normaliza al formato e164 mexicano', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'telefono' => '449 123 4567',
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.telefono', '+524491234567');
});

test('un telefono que no se reduce a 10 digitos no permite crear el proveedor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'telefono' => '123',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('telefono');
});

test('editar sin modificar el telefono ya normalizado no lo daña', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create([
        'rfc' => null,
        'telefono' => '+524491234567',
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/proveedores/{$proveedor->id}", datosProveedorValidos([
        'rfc' => null,
        'telefono' => $proveedor->telefono,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.telefono', '+524491234567');
});

test('un rfc con formato invalido no permite crear el proveedor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'rfc' => 'NO-ES-UN-RFC',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('rfc');
});

test('un rfc duplicado para el mismo usuario es rechazado', function () {
    $user = User::factory()->create();
    Proveedor::factory()->for($user)->create(['rfc' => 'AAA010101AAA']);

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'rfc' => 'AAA010101AAA',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('rfc');
});

test('el mismo rfc si puede registrarse por dos usuarios distintos', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    Proveedor::factory()->for($user1)->create(['rfc' => 'AAA010101AAA']);

    $response = $this->actingAs($user2)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'rfc' => 'AAA010101AAA',
    ]));

    $response->assertCreated();
});

test('el mismo rfc puede reutilizarse tras eliminar (soft delete) el proveedor que lo tenia', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create(['rfc' => 'AAA010101AAA']);
    $proveedor->delete();

    $response = $this->actingAs($user)->postJson('/api/v1/proveedores', datosProveedorValidos([
        'rfc' => 'AAA010101AAA',
    ]));

    $response->assertCreated();
});

test('el listado solo muestra los proveedores del usuario autenticado y permite buscar', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();

    Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Panaderia La Espiga']);
    Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Ferreteria El Tornillo']);
    Proveedor::factory()->for($otro)->create(['nombre_comercial' => 'Proveedor de Otro Usuario']);

    $response = $this->actingAs($user)->getJson('/api/v1/proveedores');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $busqueda = $this->actingAs($user)->getJson('/api/v1/proveedores?search=Espiga');
    $busqueda->assertOk();
    expect($busqueda->json('data'))->toHaveCount(1);
    $busqueda->assertJsonPath('data.0.nombre_comercial', 'Panaderia La Espiga');
});

test('un usuario no puede ver el proveedor de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $proveedor = Proveedor::factory()->for($otro)->create();

    $this->actingAs($user)->getJson("/api/v1/proveedores/{$proveedor->id}")->assertNotFound();
});

test('editar un proveedor existente persiste los cambios', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/proveedores/{$proveedor->id}", datosProveedorValidos([
        'rfc' => $proveedor->rfc,
        'nombre_comercial' => 'Nombre Comercial Actualizado',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.nombre_comercial', 'Nombre Comercial Actualizado');
    $this->assertDatabaseHas('proveedores', [
        'id' => $proveedor->id,
        'nombre_comercial' => 'Nombre Comercial Actualizado',
    ]);
});

test('eliminar un proveedor sin ordenes activas lo remueve del listado pero no lo borra fisicamente', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    // Una orden ya recibida cerró su ciclo y no bloquea el borrado (ver 012-ordenes-compra.md).
    OrdenCompra::factory()->for($user)->for($proveedor)->create(['estado' => EstadoOrdenCompra::Recibida->value]);

    $this->actingAs($user)->deleteJson("/api/v1/proveedores/{$proveedor->id}")->assertNoContent();

    $this->actingAs($user)->getJson('/api/v1/proveedores')->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('proveedores', ['id' => $proveedor->id]);
});

test('eliminar un proveedor con ordenes activas responde 409 y no lo elimina', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    OrdenCompra::factory()->for($user)->for($proveedor)->create(['estado' => EstadoOrdenCompra::Enviada->value]);

    $response = $this->actingAs($user)->deleteJson("/api/v1/proveedores/{$proveedor->id}");

    $response->assertStatus(409);
    $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id, 'deleted_at' => null]);
});

// Incluye `borrador`: si hay un borrador colgando, borrarlo es un clic, y definir "activa" con
// excepciones produce una regla que nadie recuerda (ver 012-ordenes-compra.md, supuesto #25).
test('una orden en borrador tambien bloquea el borrado del proveedor', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    OrdenCompra::factory()->for($user)->for($proveedor)->create(['estado' => EstadoOrdenCompra::Borrador->value]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/proveedores/{$proveedor->id}")
        ->assertStatus(409);
});
