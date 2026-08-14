<?php

use App\Enums\TamanoGoma;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function datosArticuloValidos(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Laptop 14 pulgadas',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_proveedor' => 1500.50,
    ], $overrides);
}

test('un invitado no puede acceder a articulos', function () {
    $this->getJson('/api/v1/articulos')->assertUnauthorized();
});

test('un usuario autenticado puede crear un articulo ligado a uno de sus catalogos', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 10, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.nombre', 'Laptop 14 pulgadas');
    $response->assertJsonPath('data.catalogo_id', $catalogo->id);
    $response->assertJsonPath('data.proveedor_id', $proveedor->id);
    // Cadena: costo = 1500.50 * (1 - 0.10) = 1350.45; con 0% de utilidad, precio de venta = costo.
    $response->assertJsonPath('data.costo_con_descuento', 1350.45);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 1350.45);
    $response->assertJsonPath('data.precio_unitario_con_iva', 1566.52);
    $this->assertDatabaseHas('articulos', [
        'user_id' => $user->id,
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
        'costo_con_descuento' => 1350.45,
        'precio_unitario_sin_iva' => 1350.45,
    ]);
});

test('un articulo con porcentaje de utilidad propio calcula su precio de venta con markup sobre el costo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 210,
        'utilidad_porcentaje' => 30,
    ]));

    $response->assertCreated();
    // costo = 210; precio = techo(210 * 1.30) = 273.00
    $response->assertJsonPath('data.costo_con_descuento', 210);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 273);
    $response->assertJsonPath('data.utilidad', 63);
    $response->assertJsonPath('data.utilidad_porcentaje_efectivo', 30);
});

test('no se puede crear un articulo sin catalogo', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('catalogo_id');
});

test('no se puede crear un articulo con el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for($otro)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogoAjeno->id,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('catalogo_id');
});

test('una clave de producto o servicio inexistente no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'clave_prod_serv' => '99999999',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('clave_prod_serv');
});

test('una clave de unidad inexistente no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'clave_unidad' => 'ZZZZZ',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('clave_unidad');
});

test('un objeto de impuesto fuera del enum no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'objeto_imp' => '99',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('objeto_imp');
});

test('un precio de proveedor menor o igual a cero no permite crear el articulo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 0,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('precio_proveedor');
});

test('un porcentaje de utilidad fuera de rango no permite crear el articulo', function (float $porcentaje) {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'utilidad_porcentaje' => $porcentaje,
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('utilidad_porcentaje');
})->with([-1, 1000, 1000.01]);

test('un porcentaje de utilidad de tres digitos es valido', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 50,
        'utilidad_porcentaje' => 300,
    ]));

    $response->assertCreated();
    // El markup no tiene singularidad matemática: 50 * 4 = 200 (ver 011).
    $response->assertJsonPath('data.precio_unitario_sin_iva', 200);
    $response->assertJsonPath('data.utilidad', 150);
});

test('un nombre duplicado en el mismo catalogo es rechazado', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre');
});

test('un nombre duplicado en un catalogo distinto del mismo proveedor tambien es rechazado', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo1 = Catalogo::factory()->for($user)->for($proveedor)->create();
    $catalogo2 = Catalogo::factory()->for($user)->for($proveedor)->create();
    Articulo::factory()->for($user)->for($catalogo1)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo2->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('nombre');
});

test('el mismo nombre si puede repetirse en un proveedor distinto', function () {
    $user = User::factory()->create();
    $catalogo1 = Catalogo::factory()->for($user)->create();
    $catalogo2 = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo1)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo2->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertCreated();
});

test('el mismo nombre puede reutilizarse en el proveedor tras eliminar (soft delete) el articulo que lo tenia', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);
    $articulo->delete();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => 'Laptop 14 pulgadas',
    ]));

    $response->assertCreated();
});

test('el listado solo muestra los articulos del usuario autenticado y permite buscar por nombre modelo o proveedor', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Distribuidora Acme']);
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas', 'modelo' => 'MOD-1']);
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Mouse inalambrico', 'modelo' => 'MOD-2']);
    Articulo::factory()->for($otro)->create(['nombre' => 'Articulo de otro usuario']);

    $response = $this->actingAs($user)->getJson('/api/v1/articulos');
    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);

    $busqueda = $this->actingAs($user)->getJson('/api/v1/articulos?search=Laptop');
    expect($busqueda->json('data'))->toHaveCount(1);

    $busquedaProveedor = $this->actingAs($user)->getJson('/api/v1/articulos?search=Acme');
    expect($busquedaProveedor->json('data'))->toHaveCount(2);
});

test('un usuario no puede ver el articulo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $articulo = Articulo::factory()->for($otro)->create();

    $this->actingAs($user)->getJson("/api/v1/articulos/{$articulo->id}")->assertNotFound();
});

test('editar un articulo existente persiste los cambios', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/articulos/{$articulo->id}", datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => $articulo->nombre,
        'modelo' => 'MOD-ACTUALIZADO',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.modelo', 'MOD-ACTUALIZADO');
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'modelo' => 'MOD-ACTUALIZADO']);
});

test('editar un articulo recalcula la cadena de precios si cambia de catalogo', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogoOrigen = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $catalogoDestino = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 25, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogoOrigen)->create([
        'precio_proveedor' => 1000,
        'costo_con_descuento' => 1000,
        'precio_unitario_sin_iva' => 1000,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/articulos/{$articulo->id}", datosArticuloValidos([
        'catalogo_id' => $catalogoDestino->id,
        'nombre' => $articulo->nombre,
        'precio_proveedor' => 1000,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.costo_con_descuento', 750);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 750);
    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'catalogo_id' => $catalogoDestino->id, 'costo_con_descuento' => 750]);
});

test('eliminar un articulo lo remueve del listado pero no lo borra fisicamente (soft delete)', function () {
    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/articulos/{$articulo->id}")->assertNoContent();

    $this->actingAs($user)->getJson('/api/v1/articulos')->assertJsonCount(0, 'data');
    $this->assertSoftDeleted('articulos', ['id' => $articulo->id]);
});

test('el catalogo de objetos de impuesto se puede consultar', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/objetos-impuesto');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(4);
    $response->assertJsonFragment(['id' => '02', 'texto' => 'Sí objeto de impuesto']);
});

test('el catalogo de claves de producto o servicio se puede buscar por texto', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/claves-prod-serv?q=notebook');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('el catalogo de claves de unidad se puede buscar por texto', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/catalogos/claves-unidad?q=pieza');

    $response->assertOk();
    expect($response->json('data'))->not->toBeEmpty();
});

test('importar un csv valido da de alta todos los articulos asociados al catalogo de la ruta', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 0]);

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Laptop 14 pulgadas,MOD-1,43211503,H87,02,1500.50\n"
        ."Mouse inalambrico,MOD-2,43211503,H87,02,299.99\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores', []);
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Laptop 14 pulgadas', 'costo_con_descuento' => 1350.45]);
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Mouse inalambrico']);
});

test('importar un csv con filas invalidas importa las validas y reporta las invalidas por fila', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Laptop 14 pulgadas,MOD-1,43211503,H87,02,1500.50\n"
        ."Articulo con clave invalida,MOD-2,00000000,H87,02,100\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 1);
    expect($response->json('errores'))->toHaveCount(1);
    expect($response->json('errores.0.fila'))->toBe(3);
    $response->assertJsonPath('errores.0.modelo', 'MOD-2');
    $this->assertDatabaseHas('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Laptop 14 pulgadas']);
    $this->assertDatabaseMissing('articulos', ['catalogo_id' => $catalogo->id, 'nombre' => 'Articulo con clave invalida']);
});

test('la fila rechazada del csv se reporta con el modelo, que es lo que la liga con su imagen', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    // Sin modelo se cae al nombre, y sin ninguno de los dos va `null`: el reporte queda como antes,
    // solo con la fila (ver 023-carga-masiva-por-pasos.md).
    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Sello con clave invalida,Printer 38,00000000,H87,02,100\n"
        ."Sello sin modelo,,00000000,H87,02,100\n"
        .",,00000000,H87,02,100\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 0);
    expect($response->json('errores'))->toHaveCount(3);
    $response->assertJsonPath('errores.0.modelo', 'Printer 38');
    $response->assertJsonPath('errores.1.modelo', 'Sello sin modelo');
    $response->assertJsonPath('errores.2.modelo', null);
});

test('la importacion csv repone el cero inicial del objeto de impuesto que se come una hoja de calculo', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Sello redondo,R-45,43211503,H87,2,188.23\n"
        ."Sello cuadrado,C-30,43211503,H87,02,150\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores', []);
    $this->assertDatabaseHas('articulos', ['nombre' => 'Sello redondo', 'objeto_imp' => '02']);
    $this->assertDatabaseHas('articulos', ['nombre' => 'Sello cuadrado', 'objeto_imp' => '02']);
});

test('la importacion csv acepta un archivo guardado en windows-1252', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Sello redondo de Ø X 45 mm,Printer R 45,43211503,H87,02,188.23\n"
        ."Sello de goma para diseño,MOD-2,43211503,H87,02,100\n";
    $archivo = UploadedFile::fake()->createWithContent(
        'articulos.csv',
        mb_convert_encoding($csv, 'Windows-1252', 'UTF-8'),
    );

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores', []);
    $this->assertDatabaseHas('articulos', ['nombre' => 'Sello redondo de Ø X 45 mm']);
    $this->assertDatabaseHas('articulos', ['nombre' => 'Sello de goma para diseño']);
});

test('la importacion csv acepta un archivo utf-8 con bom', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "\xEF\xBB\xBFnombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Sello redondo de Ø X 45 mm,Printer R 45,43211503,H87,02,188.23\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 1);
    $response->assertJsonPath('errores', []);
    $this->assertDatabaseHas('articulos', ['nombre' => 'Sello redondo de Ø X 45 mm']);
});

test('un objeto de impuesto desconocido rechaza la fila nombrando la columna y el valor recibido', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n"
        ."Sello redondo,R-45,43211503,H87,9,188.23\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 0);
    expect($response->json('errores.0.motivo'))
        ->toContain('objeto_imp "9"')
        ->toContain('01, 02, 03, 04');
});

test('un tamano de goma desconocido reporta la columna y el valor recibido', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma\n"
        ."Sello redondo,R-45,43211503,H87,02,188.23,,enorme\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    expect($response->json('errores.0.motivo'))
        ->toContain('tamano_goma "enorme"')
        ->toContain('chica, mediana, grande');
});

test('no se puede importar un csv en el catalogo de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $catalogoAjeno = Catalogo::factory()->for($otro)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogoAjeno->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ])->assertNotFound();
});

test('exportar articulos genera un csv con las columnas esperadas por la importacion', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Laptop 14 pulgadas']);

    $response = $this->actingAs($user)->get('/api/v1/articulos/exportar-csv');

    $response->assertOk();
    $contenido = $response->streamedContent();
    expect($contenido)->toContain('nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje');
    expect($contenido)->toContain('Laptop 14 pulgadas');
});

test('importar un csv respeta el porcentaje de utilidad por fila y hereda cuando la celda va vacia', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 10]);

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje\n"
        ."Con porcentaje propio,MOD-1,43211503,H87,02,100.00,25\n"
        ."Hereda del catalogo,MOD-2,43211503,H87,02,100.00,\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores', []);
    // Porcentaje propio: 100 * 1.25 = 125.
    $this->assertDatabaseHas('articulos', [
        'nombre' => 'Con porcentaje propio',
        'utilidad_porcentaje' => 25,
        'precio_unitario_sin_iva' => 125,
    ]);
    // Celda vacía: hereda el 10% del catálogo y queda con utilidad_porcentaje en NULL.
    $this->assertDatabaseHas('articulos', [
        'nombre' => 'Hereda del catalogo',
        'utilidad_porcentaje' => null,
        'precio_unitario_sin_iva' => 110,
    ]);
});

test('importar un csv rechaza por fila un porcentaje de utilidad fuera de rango', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje\n"
        ."Articulo valido,MOD-1,43211503,H87,02,100.00,25\n"
        ."Porcentaje imposible,MOD-2,43211503,H87,02,100.00,1000\n";
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $csv);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 1);
    expect($response->json('errores.0.fila'))->toBe(3);
    $this->assertDatabaseMissing('articulos', ['nombre' => 'Porcentaje imposible']);
});

test('un csv exportado conserva el porcentaje propio y la herencia al reimportarse', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 10]);
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Con porcentaje propio',
        'precio_proveedor' => 100,
        'utilidad_porcentaje' => 25,
        'costo_con_descuento' => 100,
        'precio_unitario_sin_iva' => 125,
    ]);
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Hereda del catalogo',
        'precio_proveedor' => 100,
        'utilidad_porcentaje' => null,
        'costo_con_descuento' => 100,
        'precio_unitario_sin_iva' => 110,
    ]);

    $contenido = $this->actingAs($user)->get('/api/v1/articulos/exportar-csv')->streamedContent();

    // El archivo exportado se reimporta en un catálogo nuevo sin perder la distinción entre
    // porcentaje propio y herencia.
    $destino = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 10]);
    $archivo = UploadedFile::fake()->createWithContent('articulos.csv', $contenido);

    $response = $this->actingAs($user)->postJson("/api/v1/catalogos-proveedor/{$destino->id}/articulos/importar-csv", [
        'archivo' => $archivo,
    ]);

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $this->assertDatabaseHas('articulos', [
        'catalogo_id' => $destino->id,
        'nombre' => 'Con porcentaje propio',
        'utilidad_porcentaje' => 25,
        'precio_unitario_sin_iva' => 125,
    ]);
    $this->assertDatabaseHas('articulos', [
        'catalogo_id' => $destino->id,
        'nombre' => 'Hereda del catalogo',
        'utilidad_porcentaje' => null,
        'precio_unitario_sin_iva' => 110,
    ]);
});

test('el listado ordena por las columnas numericas en ambas direcciones', function (string $sort, array $ascendente) {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    // Barato: costo 100, venta 110, utilidad 10.
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Barato', 'precio_proveedor' => 100, 'utilidad_porcentaje' => 10,
        'costo_con_descuento' => 100, 'precio_unitario_sin_iva' => 110,
    ]);
    // Caro: costo 200, venta 220, utilidad 20.
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Caro', 'precio_proveedor' => 200, 'utilidad_porcentaje' => 10,
        'costo_con_descuento' => 200, 'precio_unitario_sin_iva' => 220,
    ]);
    // Rentable: costo 150, venta 300, utilidad 150.
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Rentable', 'precio_proveedor' => 150, 'utilidad_porcentaje' => 100,
        'costo_con_descuento' => 150, 'precio_unitario_sin_iva' => 300,
    ]);

    $asc = $this->actingAs($user)->getJson("/api/v1/articulos?sort=$sort&direction=asc");
    $asc->assertOk();
    expect(collect($asc->json('data'))->pluck('nombre')->all())->toBe($ascendente);

    $desc = $this->actingAs($user)->getJson("/api/v1/articulos?sort=$sort&direction=desc");
    $desc->assertOk();
    expect(collect($desc->json('data'))->pluck('nombre')->all())->toBe(array_reverse($ascendente));
})->with([
    'costo total' => ['costo_total', ['Barato', 'Rentable', 'Caro']],
    'precio de venta' => ['precio_unitario_sin_iva', ['Barato', 'Caro', 'Rentable']],
    'utilidad' => ['utilidad', ['Barato', 'Caro', 'Rentable']],
]);

test('un sort no reconocido se ignora y el listado cae al orden por nombre', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Zeta']);
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Alfa']);

    $response = $this->actingAs($user)->getJson('/api/v1/articulos?sort=precio_secreto&direction=desc');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('nombre')->all())->toBe(['Alfa', 'Zeta']);
});

test('la clave de orden retirada costo_con_descuento cae al orden por nombre', function () {
    // Se retiró en favor de costo_total, que es lo que muestra el listado
    // (ver 014-costo-elaboracion-goma.md). Degrada sin error, como cualquier sort no reconocido.
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Zeta', 'costo_con_descuento' => 10]);
    Articulo::factory()->for($user)->for($catalogo)->create(['nombre' => 'Alfa', 'costo_con_descuento' => 99]);

    $response = $this->actingAs($user)->getJson('/api/v1/articulos?sort=costo_con_descuento&direction=asc');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('nombre')->all())->toBe(['Alfa', 'Zeta']);
});

test('el listado ordena por costo total y no por el costo del aparato', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    // Un aparato barato con goma grande supera en costo total a un aparato más caro sin goma.
    Articulo::factory()->for($user)->for($catalogo)->conGoma(TamanoGoma::Grande, 20.0)->create([
        'nombre' => 'Sello chico con goma grande', 'precio_proveedor' => 100, 'costo_con_descuento' => 100,
    ]);
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Aparato sin goma', 'precio_proveedor' => 110, 'costo_con_descuento' => 110,
        'precio_unitario_sin_iva' => 110,
    ]);

    $response = $this->actingAs($user)->getJson('/api/v1/articulos?sort=costo_total&direction=asc');

    $response->assertOk();
    expect(collect($response->json('data'))->pluck('nombre')->all())
        ->toBe(['Aparato sin goma', 'Sello chico con goma grande']);
});

test('crear un articulo con goma suma el costo vigente antes del markup', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 200,
        'tamano_goma' => 'mediana',
    ]));

    // Cadena de la historia: 200 + 10 = 210, al 25% da 262.50, utilidad 52.50.
    $response->assertCreated();
    $response->assertJsonPath('data.tamano_goma', 'mediana');
    $response->assertJsonPath('data.costo_goma', 10);
    $response->assertJsonPath('data.costo_con_descuento', 200);
    $response->assertJsonPath('data.costo_total', 210);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 262.5);
    $response->assertJsonPath('data.utilidad', 52.5);
});

test('el descuento del catalogo no se aplica sobre el costo de la goma', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 55, 'utilidad_porcentaje' => 99]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 347.27,
        'tamano_goma' => 'grande',
    ]));

    // El 55% aplica solo al aparato (347.27 -> 156.27); la goma entra completa (20.00). Si el
    // descuento tocara el total, el costo sería 158.52 y el precio otro.
    $response->assertCreated();
    $response->assertJsonPath('data.costo_con_descuento', 156.27);
    $response->assertJsonPath('data.costo_total', 176.27);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 350.78);
    $response->assertJsonPath('data.utilidad', 174.51);
});

test('un articulo sin goma conserva la cadena de 011 sin cambios', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 10, 'utilidad_porcentaje' => 25]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 200,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.tamano_goma', null);
    $response->assertJsonPath('data.costo_goma', 0);
    $response->assertJsonPath('data.costo_con_descuento', 180);
    $response->assertJsonPath('data.costo_total', 180);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 225);
    $response->assertJsonPath('data.utilidad', 45);
});

test('un tamano de goma no reconocido se rechaza', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create();

    $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'tamano_goma' => 'enorme',
    ]))->assertStatus(422)->assertJsonValidationErrors('tamano_goma');
});

test('una cadena vacia de tamano de goma equivale a no llevar goma', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 100,
        'tamano_goma' => '',
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.tamano_goma', null);
    $response->assertJsonPath('data.costo_goma', 0);
});

test('costo_goma costo_total y utilidad enviados por el cliente se ignoran', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);

    $response = $this->actingAs($user)->postJson('/api/v1/articulos', datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'precio_proveedor' => 200,
        'tamano_goma' => 'mediana',
        'costo_goma' => 9999,
        'costo_total' => 1,
        'utilidad' => 1,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.costo_goma', 10);
    $response->assertJsonPath('data.costo_total', 210);
    $response->assertJsonPath('data.utilidad', 52.5);
});

test('cambiar el tamano de goma al editar recalcula costo y precio', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 25]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->conGoma(TamanoGoma::Chica, 6.0)->create([
        'precio_proveedor' => 200, 'costo_con_descuento' => 200,
    ]);

    $response = $this->actingAs($user)->putJson("/api/v1/articulos/{$articulo->id}", datosArticuloValidos([
        'catalogo_id' => $catalogo->id,
        'nombre' => $articulo->nombre,
        'precio_proveedor' => 200,
        'tamano_goma' => 'grande',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.costo_goma', 20);
    $response->assertJsonPath('data.costo_total', 220);
    $response->assertJsonPath('data.precio_unitario_sin_iva', 275);
});

test('cambiar el descuento del catalogo respeta el costo de la goma', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $articulo = Articulo::factory()->for($user)->for($catalogo)->conGoma(TamanoGoma::Mediana, 10.0)->create([
        'precio_proveedor' => 200, 'costo_con_descuento' => 200,
    ]);

    $this->actingAs($user)->putJson("/api/v1/catalogos-proveedor/{$catalogo->id}", [
        'nombre' => $catalogo->nombre,
        'descuento' => 10,
        'utilidad_porcentaje' => 0,
    ])->assertOk();

    // El descuento baja el aparato a 180; la goma sigue costando 10.
    $articulo->refresh();
    expect((float) $articulo->costo_con_descuento)->toBe(180.0);
    expect((float) $articulo->costo_goma)->toBe(10.0);
    expect((float) $articulo->precio_unitario_sin_iva)->toBe(190.0);
});

test('la importacion csv acepta el tamano de goma con mayusculas y espacios', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    $csv = "nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma\n"
        ."Sello grande,MOD-1,43211503,H87,02,100,,Grande \n"
        ."Sello sin goma,MOD-2,43211503,H87,02,100,,\n"
        ."Sello invalido,MOD-3,43211503,H87,02,100,,enorme\n";

    $response = $this->actingAs($user)->postJson(
        "/api/v1/catalogos-proveedor/{$catalogo->id}/articulos/importar-csv",
        ['archivo' => UploadedFile::fake()->createWithContent('articulos.csv', $csv)],
    );

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $response->assertJsonPath('errores.0.fila', 4);

    $this->assertDatabaseHas('articulos', [
        'nombre' => 'Sello grande', 'tamano_goma' => 'grande', 'costo_goma' => 20, 'precio_unitario_sin_iva' => 120,
    ]);
    $this->assertDatabaseHas('articulos', [
        'nombre' => 'Sello sin goma', 'tamano_goma' => null, 'costo_goma' => 0, 'precio_unitario_sin_iva' => 100,
    ]);
});

test('la exportacion csv trae las 8 columnas y es reimportable sin perder el tamano', function () {
    $user = User::factory()->create();
    $catalogo = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);

    Articulo::factory()->for($user)->for($catalogo)->conGoma(TamanoGoma::Mediana, 10.0)->create([
        'nombre' => 'Con goma', 'precio_proveedor' => 100, 'costo_con_descuento' => 100,
    ]);
    Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sin goma', 'precio_proveedor' => 100, 'costo_con_descuento' => 100,
        'precio_unitario_sin_iva' => 100,
    ]);

    $contenido = $this->actingAs($user)->get('/api/v1/articulos/exportar-csv')->streamedContent();

    expect(strtok($contenido, "\n"))->toBe(
        'nombre,modelo,clave_prod_serv,clave_unidad,objeto_imp,precio_proveedor,utilidad_porcentaje,tamano_goma'
    );

    $destino = Catalogo::factory()->for($user)->create(['descuento' => 0, 'utilidad_porcentaje' => 0]);
    $response = $this->actingAs($user)->postJson(
        "/api/v1/catalogos-proveedor/{$destino->id}/articulos/importar-csv",
        ['archivo' => UploadedFile::fake()->createWithContent('articulos.csv', $contenido)],
    );

    $response->assertOk();
    $response->assertJsonPath('importados', 2);
    $this->assertDatabaseHas('articulos', [
        'catalogo_id' => $destino->id, 'nombre' => 'Con goma', 'tamano_goma' => 'mediana', 'costo_goma' => 10,
    ]);
    $this->assertDatabaseHas('articulos', [
        'catalogo_id' => $destino->id, 'nombre' => 'Sin goma', 'tamano_goma' => null, 'costo_goma' => 0,
    ]);
});

// Borrado en lote (ver 021-mantenimiento-articulos-catalogos.md).

test('un lote de articulos se elimina en una sola peticion', function () {
    $user = User::factory()->create();
    $articulos = Articulo::factory()->count(3)->for($user)->create();
    $sobreviviente = Articulo::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', [
        'ids' => $articulos->pluck('id')->all(),
    ]);

    $response->assertOk();
    $response->assertExactJson(['eliminados' => 3]);

    foreach ($articulos as $articulo) {
        $this->assertSoftDeleted('articulos', ['id' => $articulo->id]);
    }
    $this->assertDatabaseHas('articulos', ['id' => $sobreviviente->id, 'deleted_at' => null]);
});

// Borrar "lo que sí se pudo" sería el borrado parcial silencioso que la transacción evita.
test('un lote con un id inexistente no elimina ninguno de los demas', function () {
    $user = User::factory()->create();
    $articulos = Articulo::factory()->count(2)->for($user)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', [
        'ids' => [...$articulos->pluck('id')->all(), 999999],
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('ids.2');

    foreach ($articulos as $articulo) {
        $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'deleted_at' => null]);
    }
});

test('un lote con el articulo de otro usuario no elimina ninguno', function () {
    $user = User::factory()->create();
    $propio = Articulo::factory()->for($user)->create();
    $ajeno = Articulo::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', [
        'ids' => [$propio->id, $ajeno->id],
    ])->assertUnprocessable();

    $this->assertDatabaseHas('articulos', ['id' => $propio->id, 'deleted_at' => null]);
    $this->assertDatabaseHas('articulos', ['id' => $ajeno->id, 'deleted_at' => null]);
});

test('un lote con un articulo ya eliminado se rechaza completo', function () {
    $user = User::factory()->create();
    $vivo = Articulo::factory()->for($user)->create();
    $borrado = Articulo::factory()->for($user)->create();
    $borrado->delete();

    $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', [
        'ids' => [$vivo->id, $borrado->id],
    ])->assertUnprocessable();

    $this->assertDatabaseHas('articulos', ['id' => $vivo->id, 'deleted_at' => null]);
});

test('el borrado en lote exige al menos un id', function (mixed $ids) {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', ['ids' => $ids])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ids');
})->with([[[]], [null]]);

test('un invitado no puede eliminar articulos en lote', function () {
    $articulo = Articulo::factory()->create();

    $this->postJson('/api/v1/articulos/eliminar-lote', ['ids' => [$articulo->id]])->assertUnauthorized();

    $this->assertDatabaseHas('articulos', ['id' => $articulo->id, 'deleted_at' => null]);
});

test('los articulos eliminados en lote conservan su archivo de imagen', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $articulo = Articulo::factory()->for($user)->create();
    Storage::disk('local')->put('articulos/foto.webp', 'contenido');
    $articulo->forceFill(['imagen_ruta' => 'articulos/foto.webp'])->save();

    $this->actingAs($user)->postJson('/api/v1/articulos/eliminar-lote', ['ids' => [$articulo->id]])->assertOk();

    $this->assertSoftDeleted('articulos', ['id' => $articulo->id]);
    Storage::disk('local')->assertExists('articulos/foto.webp');
});
