<?php

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Redondeo del precio con IVA al peso entero (ver 024-precios-sin-centavos.md).
 *
 * Los casos frontera de la fórmula viven en Unit/PrecioArticuloCalculatorTest, alimentados por el
 * fixture compartido. Aquí se verifica lo que solo se ve con la base de datos de por medio: la
 * migración, la coherencia entre los distintos caminos que escriben precios, y el rótulo del
 * artículo que no causa impuesto.
 */
function correrMigracionDeRedondeo(): void
{
    $migracion = require database_path('migrations/2026_08_14_000100_redondear_precios_de_articulos_a_peso_entero.php');
    $migracion->up();
}

test('la migracion deja todos los precios con IVA en un peso entero', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    // Precios como los dejaba 011/014: el markup crudo, con centavos al aplicarles el IVA. Se
    // escriben por DB directa para saltarse la cadena, que ya redondea.
    foreach ([201.28, 110.00, 1350.45, 5.20] as $precio) {
        $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
            'precio_proveedor' => $precio,
            'costo_con_descuento' => $precio,
        ]);

        DB::table('articulos')->where('id', $articulo->id)->update(['precio_unitario_sin_iva' => $precio]);
    }

    correrMigracionDeRedondeo();

    foreach (Articulo::all() as $articulo) {
        expect($articulo->precio_unitario_con_iva)
            ->toBe(floor($articulo->precio_unitario_con_iva), "artículo {$articulo->id}");
    }
});

test('la migracion es idempotente', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);
    Articulo::factory()->count(5)->for($user)->for($catalogo)->create();

    correrMigracionDeRedondeo();
    $primera = Articulo::orderBy('id')->pluck('precio_unitario_sin_iva', 'id')->toArray();

    correrMigracionDeRedondeo();
    $segunda = Articulo::orderBy('id')->pluck('precio_unitario_sin_iva', 'id')->toArray();

    expect($segunda)->toBe($primera);
});

test('la migracion no toca las entradas capturadas', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 347.27,
        'utilidad_porcentaje' => 99,
    ]);

    correrMigracionDeRedondeo();

    $articulo->refresh();
    expect((float) $articulo->precio_proveedor)->toBe(347.27)
        ->and((float) $articulo->utilidad_porcentaje)->toBe(99.0);
});

test('un articulo que no causa impuesto redondea el precio a secas', function (string $objetoImp) {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'objeto_imp' => $objetoImp,
        'precio_proveedor' => 201.28,
    ]));

    $response->assertCreated();
    // Sin un 16% que el cliente vea sumarse encima, el número entero tiene que ser el precio a
    // secas: 201.28 sube a 202.00 y el precio "con IVA" es el mismo valor.
    $response->assertJsonPath('data.precio_unitario_sin_iva', 202);
    $response->assertJsonPath('data.precio_unitario_con_iva', 202);
})->with(['01', '03', '04']);

test('un articulo que si causa impuesto redondea el precio con IVA', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'objeto_imp' => ObjetoImpuesto::SiObjeto->value,
        'precio_proveedor' => 201.28,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.precio_unitario_sin_iva', 201.72);
    $response->assertJsonPath('data.precio_unitario_con_iva', 234);
});

test('todos los caminos que escriben precios producen el mismo entero', function () {
    // El eslabón vive dentro de `calcularCadena`, así que ningún camino puede quedarse fuera: alta
    // individual, edición, importación CSV, recálculo por descuento del catálogo, recálculo por
    // utilidad del catálogo y aumento masivo tienen que coincidir al centavo.
    $user = User::factory()->create();

    $porAlta = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25])->id,
        'nombre' => 'Por alta',
        'precio_proveedor' => 200,
    ]))->json('data.precio_unitario_sin_iva');

    // Mismas entradas, pero llegando por un cambio de descuento del catálogo.
    $catalogoDescuento = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);
    $porDescuento = Articulo::factory()->for($user)->for($catalogoDescuento)->create(['precio_proveedor' => 200]);
    $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogoDescuento->id}", [
        'nombre' => $catalogoDescuento->nombre,
        'descuento' => 10,
        'utilidad_porcentaje' => 25,
    ])->assertOk();

    // Y por un cambio de utilidad del catálogo, que es el camino que no pasa por `calcularCadena`.
    $catalogoUtilidad = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 0]);
    $porUtilidad = Articulo::factory()->for($user)->for($catalogoUtilidad)->create([
        'precio_proveedor' => 200,
        'costo_con_descuento' => 180,
    ]);
    $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogoUtilidad->id}", [
        'nombre' => $catalogoUtilidad->nombre,
        'descuento' => 10,
        'utilidad_porcentaje' => 25,
    ])->assertOk();

    expect((float) $porAlta)->toBe(225.0)
        ->and((float) $porDescuento->refresh()->precio_unitario_sin_iva)->toBe(225.0)
        ->and((float) $porUtilidad->refresh()->precio_unitario_sin_iva)->toBe(225.0);
});

test('cambiar la utilidad del catalogo deja el precio con IVA en un entero', function () {
    // El recálculo por utilidad no pasa por `calcularCadena`, así que aplica el redondeo por su
    // cuenta; sin eso sería la única vía capaz de dejar centavos.
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'precio_proveedor' => 210,
        'costo_con_descuento' => 210,
    ]);

    $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 0,
        'utilidad_porcentaje' => 30,
    ])->assertOk();

    $articulo->refresh();
    expect((float) $articulo->precio_unitario_sin_iva)->toBe(273.28)
        ->and($articulo->precio_unitario_con_iva)->toBe(317.0);
});
