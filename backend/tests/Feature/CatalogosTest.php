<?php

use App\Enums\TamanoGoma;
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

test('crear un catalogo con utilidad distribuidor la persiste y la expone', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', datosCatalogoValidos([
        'proveedor_id' => $proveedor->id,
        'utilidad_distribuidor_porcentaje' => 25,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.utilidad_distribuidor_porcentaje', 25);
});

test('omitir la utilidad distribuidor al crear un catalogo la deja en cero', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/catalogos-proveedor', [
        'proveedor_id' => $proveedor->id,
        'nombre' => 'Catálogo Base',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.utilidad_distribuidor_porcentaje', 0);
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
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create([
        'descuento' => 0, 'utilidad_porcentaje' => 0, 'utilidad_distribuidor_porcentaje' => 15,
    ]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 1000,
        'costo_con_descuento' => 1000,
        'precio_unitario_sin_iva' => 1000,
        'precio_distribuidor_sin_iva' => 1000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 20,
        'utilidad_porcentaje' => 0,
        'utilidad_distribuidor_porcentaje' => 15,
    ]);

    $response->assertOk();
    // El descuento mueve AMBOS precios (ver 033-precio-distribuidor.md): el directo cae a 800.00, y
    // el distribuidor (800 al 15%, sin goma) da 920 crudo, que el redondeo de 024 sube a 920.69.
    $this->assertDatabaseHas('articulos', [
        'id' => $articulo->id, 'costo_con_descuento' => 800, 'precio_unitario_sin_iva' => 800,
        'precio_distribuidor_sin_iva' => 920.69,
    ]);
});

test('editar la utilidad distribuidor de un catalogo recalcula solo los articulos que la heredan', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create([
        'descuento' => 0, 'utilidad_porcentaje' => 40, 'utilidad_distribuidor_porcentaje' => 0,
    ]);
    // Hereda la utilidad distribuidor del catálogo (utilidad_distribuidor_porcentaje NULL).
    $hereda = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
        'precio_unitario_sin_iva' => 210,
        'precio_distribuidor_sin_iva' => 210,
        'utilidad_distribuidor_porcentaje' => null,
    ]);
    // Tiene utilidad distribuidor propia: no debe moverse.
    $propio = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
        'precio_unitario_sin_iva' => 210,
        'precio_distribuidor_sin_iva' => 999,
        'utilidad_distribuidor_porcentaje' => 5,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 0,
        'utilidad_porcentaje' => 40,
        'utilidad_distribuidor_porcentaje' => 30,
    ]);

    $response->assertOk();
    // Cambiar solo utilidad_porcentaje o utilidad_distribuidor_porcentaje no debe mover el precio
    // directo de ningún artículo (no cambió): sigue en 210.
    // El que hereda: 210 al 30% da 273 crudo, que el redondeo de 024 sube a 273.28.
    $this->assertDatabaseHas('articulos', [
        'id' => $hereda->id, 'precio_unitario_sin_iva' => 210, 'precio_distribuidor_sin_iva' => 273.28,
    ]);
    // El que tiene utilidad distribuidor propia conserva la suya, sin tocar.
    $this->assertDatabaseHas('articulos', [
        'id' => $propio->id, 'precio_unitario_sin_iva' => 210, 'precio_distribuidor_sin_iva' => 999,
    ]);
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
    // El que hereda pasa de 210 a techo(210 * 1.30) = 273 crudo, que el redondeo de 024 sube a
    // 273.28 para dejar el precio con IVA en $317.00.
    $this->assertDatabaseHas('articulos', ['id' => $hereda->id, 'precio_unitario_sin_iva' => 273.28]);
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

test('el endpoint de impacto de precios incluye el precio distribuidor', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create([
        'descuento' => 0, 'utilidad_porcentaje' => 0, 'utilidad_distribuidor_porcentaje' => 0,
    ]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
        'precio_unitario_sin_iva' => 210,
        'precio_distribuidor_sin_iva' => 210,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/impacto-precios", [
        'descuento' => 0,
        'utilidad_porcentaje' => 0,
        'utilidad_distribuidor_porcentaje' => 30,
    ]);

    $response->assertOk();
    $response->assertJsonPath('articulos.0.id', $articulo->id);
    // 210 sin goma al 30% da 273 crudo, que el redondeo de 024 sube a 273.28.
    $response->assertJsonPath('articulos.0.precio_distribuidor_sin_iva', 273.28);
    // No debe persistir nada.
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'precio_distribuidor_sin_iva' => 210]);
});

// Ver 021-mantenimiento-articulos-catalogos.md: la vista previa cubre también el aumento, y los
// tres parámetros caen a lo que el catálogo ya tiene guardado.
test('el impacto de precios acepta un aumento y cae al descuento y la utilidad guardados', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 200,
        'costo_con_descuento' => 180,
        'precio_unitario_sin_iva' => 225,
    ]);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/impacto-precios", [
        'aumento_porcentaje' => 5,
    ]);

    $response->assertOk();
    // 200 + 5% = 210 → −10% = 189 → +25% = 236.25 crudo → redondeo de 024 = 237.07 ($275.00 con IVA)
    $response->assertJsonPath('articulos.0.precio_proveedor', 210);
    $response->assertJsonPath('articulos.0.costo_con_descuento', 189);
    $response->assertJsonPath('articulos.0.precio_unitario_sin_iva', 237.07);
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'precio_proveedor' => 200]);
});

test('un aumento del cinco por ciento sube el precio de proveedor y recalcula la cadena', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 200,
        'costo_con_descuento' => 180,
        'precio_unitario_sin_iva' => 225,
    ]);

    $response = $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 5]);

    $response->assertOk();
    $response->assertExactJson(['actualizados' => 1]);

    $articulo->refresh();
    expect((float) $articulo->precio_proveedor)->toBe(210.0)
        ->and((float) $articulo->costo_con_descuento)->toBe(189.0)
        ->and((float) $articulo->precio_unitario_sin_iva)->toBe(237.07)
        ->and($articulo->precio_unitario_con_iva)->toBe(275.0);
});

test('el aumento de costos tambien recalcula el precio distribuidor', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create([
        'descuento' => 10, 'utilidad_porcentaje' => 0, 'utilidad_distribuidor_porcentaje' => 15,
    ]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 100,
        'costo_con_descuento' => 90,
        'precio_unitario_sin_iva' => 90,
        'precio_distribuidor_sin_iva' => 90,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 10])
        ->assertOk();

    // 100 sube 10% a 110; con el 10% de descuento del catálogo el costo con descuento queda en 99.
    // El distribuidor (sin goma) al 15% da 113.85 crudo, que el redondeo de 024 sube a 115.52 para
    // dejar el precio con IVA en $134.00.
    $articulo->refresh();
    expect((float) $articulo->precio_proveedor)->toBe(110.0)
        ->and((float) $articulo->costo_con_descuento)->toBe(99.0)
        ->and((float) $articulo->precio_distribuidor_sin_iva)->toBe(115.52)
        ->and($articulo->precio_distribuidor_con_iva)->toBe(134.0);
});

test('el aumento no toca el descuento del catalogo, la utilidad del articulo ni el costo de goma', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);
    $propio = Articulo::factory()->for($user)->for($catalogo)->conGoma(TamanoGoma::Mediana, 10.0)->create([
        'utilidad_porcentaje' => 50,
        'precio_proveedor' => 100,
        'costo_con_descuento' => 90,
    ]);
    $heredado = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 100,
        'costo_con_descuento' => 90,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 10])
        ->assertOk();

    $catalogo->refresh();
    expect((float) $catalogo->descuento)->toBe(10.0)
        ->and((float) $catalogo->utilidad_porcentaje)->toBe(25.0);

    // El que tiene porcentaje propio lo conserva, y la goma no se aumenta:
    // 110 → −10% = 99 → +10 de goma = 109 → +50% = 163.50 crudo → redondeo de 024 = 163.79
    $propio->refresh();
    expect((float) $propio->utilidad_porcentaje)->toBe(50.0)
        ->and((float) $propio->costo_goma)->toBe(10.0)
        ->and((float) $propio->precio_unitario_sin_iva)->toBe(163.79)
        ->and($propio->precio_unitario_con_iva)->toBe(190.0);

    // El que hereda sigue heredando: 110 → −10% = 99 → +25% = 123.75 crudo ($143.55 con IVA), que el
    // redondeo de 024 sube a 124.14 para aterrizar en $144.00.
    $heredado->refresh();
    expect($heredado->utilidad_porcentaje)->toBeNull()
        ->and((float) $heredado->precio_unitario_sin_iva)->toBe(124.14)
        ->and($heredado->precio_unitario_con_iva)->toBe(144.0);
});

test('el nuevo precio de proveedor se redondea a centavos', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 199.99,
        'costo_con_descuento' => 199.99,
        'precio_unitario_sin_iva' => 199.99,
    ]);

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 5])
        ->assertOk();

    // 199.99 × 1.05 = 209.9895 → 209.99
    expect((float) $articulo->refresh()->precio_proveedor)->toBe(209.99);
});

test('un aumento cuyo efecto no llega a medio centavo deja el articulo igual', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 1,
        'costo_con_descuento' => 1,
        'precio_unitario_sin_iva' => 1,
    ]);

    // 1.00 × 1.004 = 1.004 → 1.00. Medio centavo justo (0.5%) sí subiría: round() lo lleva a 1.01.
    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 0.4])
        ->assertOk();

    expect((float) $articulo->refresh()->precio_proveedor)->toBe(1.0);
});

test('la vista previa del aumento coincide al centavo con lo que queda guardado', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 12.5, 'utilidad_porcentaje' => 33.33]);
    Articulo::factory()->count(5)->for($user)->for($catalogo)->create();

    $previa = $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/impacto-precios", ['aumento_porcentaje' => 7.25])
        ->assertOk()
        ->json('articulos');

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 7.25])
        ->assertOk();

    foreach ($previa as $proyectado) {
        $articulo = Articulo::findOrFail($proyectado['id']);

        // El cast a float de ambos lados es por el JSON: un valor redondo viaja como entero.
        expect((float) $articulo->precio_proveedor)->toBe((float) $proyectado['precio_proveedor'])
            ->and((float) $articulo->costo_con_descuento)->toBe((float) $proyectado['costo_con_descuento'])
            ->and((float) $articulo->precio_unitario_sin_iva)->toBe((float) $proyectado['precio_unitario_sin_iva']);
    }
});

test('el aumento solo alcanza a los articulos del catalogo indicado', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $otro = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $ajeno = Articulo::factory()->for($user)->for($otro)->create([
        'precio_proveedor' => 100,
        'costo_con_descuento' => 100,
        'precio_unitario_sin_iva' => 100,
    ]);
    Articulo::factory()->for($user)->for($catalogo)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => 10])
        ->assertExactJson(['actualizados' => 1]);

    expect((float) $ajeno->refresh()->precio_proveedor)->toBe(100.0);
});

test('el aumento rechaza el cero, los negativos, los mayores a cien y los de tres decimales', function (mixed $aumento) {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/aumentar-costos", ['aumento_porcentaje' => $aumento])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('aumento_porcentaje');
})->with([0, -5, 100.01, 150, 5.005, 'abc', null]);

test('no se puede aumentar el costo del catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)
        ->postJson("/api/v1/catalogos-proveedor/{$catalogoAjeno->id}/aumentar-costos", ['aumento_porcentaje' => 5])
        ->assertNotFound();
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

// Antes respondía 409, lo que dejaba sin salida a quien quisiera borrar un catálogo de cientos de
// artículos (ver 021-mantenimiento-articulos-catalogos.md).
test('eliminar un catalogo con articulos se lleva tambien sus articulos', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulos = Articulo::factory()->count(3)->for($user)->for($catalogo)->create();

    $this->actingAs($user)->deleteJson("/api/v1/catalogos-proveedor/{$catalogo->id}")->assertNoContent();

    $this->assertSoftDeleted('catalogos', ['id' => $catalogo->id]);
    foreach ($articulos as $articulo) {
        $this->assertSoftDeleted('articulos', ['id' => $articulo->id]);
    }
    $this->actingAs($user)->getJson('/api/v1/articulos')->assertJsonCount(0, 'data');
});

test('eliminar un catalogo no toca al proveedor ni a los demas catalogos del proveedor', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Se va']);
    $otroCatalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['nombre' => 'Se queda']);
    $articuloAjeno = Articulo::factory()->for($user)->for($otroCatalogo)->create();
    Articulo::factory()->for($user)->for($catalogo)->create();

    $this->actingAs($user)->deleteJson("/api/v1/catalogos-proveedor/{$catalogo->id}")->assertNoContent();

    $this->assertDatabaseHas('proveedores', ['id' => $proveedor->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('catalogos', ['id' => $otroCatalogo->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('articulos', ['id' => $articuloAjeno->id, 'deleted_at' => null]);
});

test('el listado y el detalle de catalogos exponen cuantos articulos tiene cada uno', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->count(2)->for($user)->for($catalogo)->create();

    $this->actingAs($user)->getJson('/api/v1/catalogos-proveedor')
        ->assertOk()
        ->assertJsonPath('data.0.articulos_count', 2);

    $this->actingAs($user)->getJson("/api/v1/catalogos-proveedor/{$catalogo->id}")
        ->assertOk()
        ->assertJsonPath('data.articulos_count', 2);
});
