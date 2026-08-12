<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;

function datosCatalogoValidos(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Catálogo Premium',
        'descuento' => 10,
        'utilidad_porcentaje' => 0,
    ], $overrides);
}

test('un invitado no puede acceder a catalogos', function () {
    $this->getJson('/api/v1/catalogos-proveedor')->assertUnauthorized();
});

test('un usuario autenticado puede crear un catalogo para uno de sus proveedores', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor->id,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.nombre', 'Catálogo Premium');
    $response->assertJsonPath('data.descuento', 10);
    $response->assertJsonPath('data.utilidad_porcentaje', 0);
    $response->assertJsonPath('data.proveedor_id', $proveedor->id);
    $this->assertDatabaseHas('catalogos', [
        'user_id' => $user->id,
        'proveedor_id' => $proveedor->id,
        'nombre' => 'Catálogo Premium',
    ]);
});

test('omitir el descuento y la utilidad al crear un catalogo los deja en cero', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', [
        'proveedor_id' => $proveedor->id,
        'nombre' => 'Catálogo Base',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.descuento', 0);
    $response->assertJsonPath('data.utilidad_porcentaje', 0);
});

test('no se puede crear un catalogo sin proveedor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('proveedor_id');
});

test('no se puede crear un catalogo con el proveedor de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $proveedorAjeno = Proveedor::factory()->for($otro)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedorAjeno->id,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('proveedor_id');
});

test('un descuento fuera del rango 0 a 100 no permite crear el catalogo', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor->id,
        'descuento' => 150,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('descuento');
});

test('un porcentaje de utilidad fuera de rango no permite crear el catalogo', function (float $porcentaje) {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor->id,
        'utilidad_porcentaje' => $porcentaje,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('utilidad_porcentaje');
})->with([-1, 1000, 1000.01]);

test('un nombre de catalogo duplicado en el mismo proveedor es rechazado', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Catálogo Premium']);

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor->id,
        'nombre' => 'Catálogo Premium',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre');
});

test('el mismo nombre de catalogo si puede repetirse en un proveedor distinto', function () {
    $user = User::factory()->create();
    $proveedor1 = Proveedor::factory()->for($user)->create();
    $proveedor2 = Proveedor::factory()->for($user)->create();
    Catalogo::factory()->for($user)->for($proveedor1)->create(['nombre' => 'Catálogo Premium']);

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor2->id,
        'nombre' => 'Catálogo Premium',
    ]));

    $response->assertCreated();
});

test('el listado solo muestra los catalogos del usuario autenticado y permite buscar', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Distribuidora Acme']);

    Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Catálogo Premium']);
    Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Catálogo Básico']);
    Catalogo::factory()->for($otro)->create(['nombre' => 'Catálogo de otro usuario']);

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos-proveedor');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $busqueda = $this->actingAs($user)->getJson('/api/v1/catalogos-proveedor?search=Premium');
    expect($busqueda->json('data'))->toHaveCount(1);

    $busquedaProveedor = $this->actingAs($user)->getJson('/api/v1/catalogos-proveedor?search=Acme');
    expect($busquedaProveedor->json('data'))->toHaveCount(2);
});

test('un usuario no puede ver el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogo = Catalogo::factory()->for($otro)->create();

    $this->actingAs($user)->getJson("/api/v1/catalogos-proveedor/{$catalogo->id}")->assertNotFound();
});

test('editar un catalogo existente permite modificar nombre descuento y utilidad', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Catálogo Original', 'descuento' => 5, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => 'Catálogo Renombrado',
        'descuento' => 20,
        'utilidad_porcentaje' => 15,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.nombre', 'Catálogo Renombrado');
    $response->assertJsonPath('data.descuento', 20);
    $response->assertJsonPath('data.utilidad_porcentaje', 15);
    $this->assertDatabaseHas('catalogos', ['id' => $catalogo->id, 'nombre' => 'Catálogo Renombrado']);
});

test('editar un catalogo no permite cambiar el proveedor', function () {
    $user = User::factory()->create();
    $proveedorOriginal = Proveedor::factory()->for($user)->create();
    $proveedorNuevo = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedorOriginal)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", datosCatalogoValidos([
        'proveedor_id' => $proveedorNuevo->id,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.proveedor_id', $proveedorOriginal->id);
    $this->assertDatabaseHas('catalogos', ['id' => $catalogo->id, 'proveedor_id' => $proveedorOriginal->id]);
});

test('editar el descuento de un catalogo recalcula la cadena de precios de sus articulos', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 1000,
        'costo_con_descuento' => 1000,
        'precio_unitario_sin_iva' => 1000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 20,
        'utilidad_porcentaje' => 0,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'costo_con_descuento' => 800, 'precio_unitario_sin_iva' => 800]);
});

test('editar la utilidad de un catalogo recalcula el precio de venta de los articulos que heredan el porcentaje', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    // Artículo que hereda el porcentaje del catálogo (utilidad_porcentaje NULL).
    $hereda = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
        'precio_unitario_sin_iva' => 210,
        'utilidad_porcentaje' => null,
    ]);
    // Artículo con porcentaje propio: su precio no debe moverse.
    $propio = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
        'precio_unitario_sin_iva' => 252,
        'utilidad_porcentaje' => 20,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 0,
        'utilidad_porcentaje' => 30,
    ]);

    $response->assertOk();
    // El que hereda pasa de 210 a techo(210 * 1.30) = 273.
    $this->assertDatabaseHas('articulos', ['id' => $hereda->id, 'precio_unitario_sin_iva' => 273]);
    // El que tiene porcentaje propio conserva su precio, calculado con su propio 20%.
    $this->assertDatabaseHas('articulos', ['id' => $propio->id, 'precio_unitario_sin_iva' => 252]);
});

test('el endpoint de impacto de precios devuelve la vista previa sin persistir', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 1000,
        'costo_con_descuento' => 1000,
        'precio_unitario_sin_iva' => 1000,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/impacto-precios", [
        'descuento' => 20,
        'utilidad_porcentaje' => 0,
    ]);

    $response->assertOk();
    $response->assertJsonPath('articulos.0.id', $articulo->id);
    $response->assertJsonPath('articulos.0.costo_con_descuento', 800);
    $response->assertJsonPath('articulos.0.precio_unitario_sin_iva', 800);
    // No debe persistir nada.
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'costo_con_descuento' => 1000, 'precio_unitario_sin_iva' => 1000]);
});

test('el endpoint de impacto de precios no permite consultar el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for($otro)->create();

    $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogoAjeno->id}/impacto-precios", [
        'descuento' => 10,
        'utilidad_porcentaje' => 0,
    ])->assertNotFound();
});

test('eliminar un catalogo sin articulos lo remueve del listado pero no lo borra fisicamente', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/catalogos-proveedor/{$catalogo->id}")->assertNoContent();

    $this->actingAs($user)->getJson('/api/v1/catalogos-proveedor')->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('catalogos', ['id' => $catalogo->id]);
});

test('eliminar un catalogo con articulos asociados responde 409 y no lo elimina', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create();

    $response = $this->actingAs($user)->deleteJson("/api/v1/catalogos-proveedor/{$catalogo->id}");

    $response->assertStatus(409);
    $this->assertDatabaseHas('catalogos', ['id' => $catalogo->id, 'deleted_at' => null]);
});
