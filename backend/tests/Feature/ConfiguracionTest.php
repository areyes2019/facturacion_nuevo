<?php

use App\Enums\ClaveConfiguracion;
use App\Enums\TamanoGoma;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Configuracion;
use App\Models\User;

/**
 * Almacén de ajustes globales y costo de elaboración de goma
 * (ver 014-costo-elaboracion-goma.md).
 */
test('un invitado no puede acceder a la configuracion', function () {
    $this->getJson('/api/v1/configuracion')->assertUnauthorized();
    $this->putJson('/api/v1/configuracion', ['costo_goma_chica' => 7])->assertUnauthorized();
});

test('la configuracion devuelve todas las claves con sus valores de fabrica sin haber guardado nunca', function () {
    $user = User::factory()->create();

    // Ninguna fila en la tabla: el valor efectivo lo aporta el enum.
    $this->assertDatabaseCount('configuraciones', 0);

    $response = $this->actingAs($user)->getJson('/api/v1/configuracion');

    $response->assertOk();
    $response->assertExactJson([
        'costo_goma_chica' => '6.00',
        'costo_goma_mediana' => '10.00',
        'costo_goma_grande' => '20.00',
        'costo_goma_jumbo' => '40.00',
        'mensaje_ticket' => ClaveConfiguracion::MensajeTicket->valorPorDefecto(),
        'mensaje_listo' => ClaveConfiguracion::MensajeListo->valorPorDefecto(),
    ]);
});

test('el mensaje del ticket se puede guardar y tambien vaciar', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/configuracion', [
        'mensaje_ticket' => 'Gracias {nombre}, tu ticket es el {folio}.',
    ])->assertOk()->assertJsonPath('mensaje_ticket', 'Gracias {nombre}, tu ticket es el {folio}.');

    // A diferencia de los costos, el mensaje admite quedar vacío: el usuario puede no querer mandar
    // nada junto con la imagen del ticket.
    $this->actingAs($user)->putJson('/api/v1/configuracion', ['mensaje_ticket' => ''])
        ->assertOk()
        ->assertJsonPath('mensaje_ticket', '');
});

test('guardar una clave deja intactas las ausentes', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->putJson('/api/v1/configuracion', [
        'costo_goma_grande' => '25.50',
    ]);

    $response->assertOk();
    $response->assertJson([
        'costo_goma_chica' => '6.00',
        'costo_goma_mediana' => '10.00',
        'costo_goma_grande' => '25.50',
    ]);
    $this->assertDatabaseCount('configuraciones', 1);
});

test('una clave desconocida se rechaza y no crea fila', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/configuracion', ['costo_tinta_azul' => '3.00'])
        ->assertStatus(422);

    $this->assertDatabaseCount('configuraciones', 0);
});

test('un costo de goma negativo se rechaza y cero se acepta', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/configuracion', ['costo_goma_chica' => '-1.00'])
        ->assertStatus(422);

    $this->actingAs($user)
        ->putJson('/api/v1/configuracion', ['costo_goma_chica' => '0.00'])
        ->assertOk()
        ->assertJsonPath('costo_goma_chica', '0.00');
});

test('los ajustes de un usuario no son visibles ni modificables por otro', function () {
    $ana = User::factory()->create();
    $beto = User::factory()->create();

    $this->actingAs($ana)->putJson('/api/v1/configuracion', ['costo_goma_mediana' => '99.00']);

    // Beto ve sus propios valores de fábrica, no los de Ana.
    $this->actingAs($beto)
        ->getJson('/api/v1/configuracion')
        ->assertJsonPath('costo_goma_mediana', '10.00');

    // Y guardar los suyos no toca la fila de Ana.
    $this->actingAs($beto)->putJson('/api/v1/configuracion', ['costo_goma_mediana' => '11.00']);

    expect(Configuracion::where('user_id', $ana->id)->where('clave', 'costo_goma_mediana')->value('valor'))
        ->toBe('99.00');
});

test('cambiar un costo recalcula solo los articulos de esa categoria', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);

    $mediana = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Mediana, 10.0)
        ->create(['nombre' => 'Sello mediano', 'precio_proveedor' => 200, 'costo_con_descuento' => 200]);
    $grande = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Grande, 20.0)
        ->create(['nombre' => 'Sello grande', 'precio_proveedor' => 200, 'costo_con_descuento' => 200]);
    $jumbo = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Jumbo, 40.0)
        ->create(['nombre' => 'Sello jumbo', 'precio_proveedor' => 200, 'costo_con_descuento' => 200]);
    $sinGoma = Articulo::factory()->for($user)->for($catalogo)
        ->create(['nombre' => 'Tinta', 'precio_proveedor' => 200, 'costo_con_descuento' => 200]);

    // Precios de partida con 25% de utilidad del catálogo.
    $mediana->update(['precio_unitario_sin_iva' => 262.50]);
    $grande->update(['precio_unitario_sin_iva' => 275.00]);
    $jumbo->update(['precio_unitario_sin_iva' => 300.00]);
    $sinGoma->update(['precio_unitario_sin_iva' => 250.00]);

    $this->actingAs($user)
        ->putJson('/api/v1/configuracion', ['costo_goma_mediana' => '12.00'])
        ->assertOk();

    // El mediano mueve costo congelado y precio: (200 + 12) * 1.25 = 265.00.
    expect((float) $mediana->fresh()->costo_goma)->toBe(12.0);
    expect((float) $mediana->fresh()->precio_unitario_sin_iva)->toBe(265.0);

    // El grande, el jumbo y el que no lleva goma no se mueven.
    expect((float) $grande->fresh()->costo_goma)->toBe(20.0);
    expect((float) $grande->fresh()->precio_unitario_sin_iva)->toBe(275.0);
    expect((float) $jumbo->fresh()->costo_goma)->toBe(40.0);
    expect((float) $jumbo->fresh()->precio_unitario_sin_iva)->toBe(300.0);
    expect((float) $sinGoma->fresh()->costo_goma)->toBe(0.0);
    expect((float) $sinGoma->fresh()->precio_unitario_sin_iva)->toBe(250.0);
});

test('cambiar el costo de jumbo recalcula solo los articulos jumbo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);

    $jumbo = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Jumbo, 40.0)
        ->create(['nombre' => 'Sello jumbo', 'precio_proveedor' => 200, 'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 300.00]);
    $grande = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Grande, 20.0)
        ->create(['nombre' => 'Sello grande', 'precio_proveedor' => 200, 'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 275.00]);

    $this->actingAs($user)
        ->putJson('/api/v1/configuracion', ['costo_goma_jumbo' => '50.00'])
        ->assertOk();

    // El jumbo mueve costo congelado y precio: (200 + 50) * 1.25 = 312.50.
    expect((float) $jumbo->fresh()->costo_goma)->toBe(50.0);
    expect((float) $jumbo->fresh()->precio_unitario_sin_iva)->toBe(312.50);

    // El grande no se mueve.
    expect((float) $grande->fresh()->costo_goma)->toBe(20.0);
    expect((float) $grande->fresh()->precio_unitario_sin_iva)->toBe(275.0);
});

test('el endpoint de impacto cuenta solo los articulos cuyo precio cambiaria', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);

    foreach (['A', 'B'] as $nombre) {
        Articulo::factory()->for($user)->for($catalogo)
            ->conGoma(TamanoGoma::Mediana, 10.0)
            ->create(['nombre' => "Mediano $nombre", 'precio_proveedor' => 200, 'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 262.50]);
    }
    Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Grande, 20.0)
        ->create(['nombre' => 'Grande', 'precio_proveedor' => 200, 'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 275.0]);
    Articulo::factory()->for($user)->for($catalogo)
        ->create(['nombre' => 'Sin goma', 'precio_proveedor' => 200, 'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 250.0]);

    // Cambiar el costo de la mediana afecta a dos artículos.
    $this->actingAs($user)
        ->getJson('/api/v1/configuracion/impacto-precios?costo_goma_mediana=12.00')
        ->assertOk()
        ->assertExactJson(['articulos_afectados' => 2]);

    // Enviar el costo vigente sin cambio no afecta a nadie.
    $this->actingAs($user)
        ->getJson('/api/v1/configuracion/impacto-precios?costo_goma_mediana=10.00')
        ->assertOk()
        ->assertExactJson(['articulos_afectados' => 0]);

    // Los dos costos a la vez suman las dos categorías.
    $this->actingAs($user)
        ->getJson('/api/v1/configuracion/impacto-precios?costo_goma_mediana=12.00&costo_goma_grande=21.00')
        ->assertOk()
        ->assertExactJson(['articulos_afectados' => 3]);
});

test('el impacto no persiste nada', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)
        ->conGoma(TamanoGoma::Chica, 6.0)
        ->create(['precio_proveedor' => 100, 'costo_con_descuento' => 100, 'precio_unitario_sin_iva' => 132.50]);

    $this->actingAs($user)->getJson('/api/v1/configuracion/impacto-precios?costo_goma_chica=50.00')->assertOk();

    expect((float) $articulo->fresh()->costo_goma)->toBe(6.0);
    expect((float) $articulo->fresh()->precio_unitario_sin_iva)->toBe(132.50);
    $this->assertDatabaseCount('configuraciones', 0);
});
