<?php

use App\Enums\EstadoFactura;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Emisor;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\QrTimbreFiscal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Formato unificado de los tres documentos impresos (ver 019-formato-pdf-documentos.md).
 *
 * Las aserciones de contenido corren sobre el HTML de la plantilla; las de "se genera" corren sobre
 * los endpoints reales, que sí pasan por dompdf. Ninguna prueba juzga cómo se ve el documento: eso
 * lo aprueba una persona.
 */
function emisorCompleto(array $overrides = []): Emisor
{
    return Emisor::create(array_merge([
        'nombre' => 'ABDIAS REYES REYNA',
        'rfc' => 'RERA7701272R1',
        'regimen_fiscal' => '612',
        'domicilio' => '38024, Celaya, Guanajuato, MEX',
        'correo' => 'facturacion@ejemplo.test',
        'telefono' => '4611234567',
    ], $overrides));
}

/**
 * Artículo con su cadena de proveedor y catálogo, para que la línea tenga Clave SAT que imprimir.
 */
function articuloParaPdf(User $user): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0]);

    return Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello de goma',
        'modelo' => 'MOD-4321',
        'clave_prod_serv' => '44121801',
    ]);
}

function facturaTimbrada(User $user, array $overrides = []): Factura
{
    $cliente = Cliente::factory()->for($user)->create([
        'razon_social' => 'Clientes Unidos SA de CV',
        'regimen_fiscal' => '601',
        'direccion_comercial' => 'Av. Siempre Viva 742',
    ]);

    $factura = Factura::factory()->for($user)->for($cliente)->create(array_merge([
        'estado' => EstadoFactura::Timbrada->value,
        'facturapi_serie' => 'F',
        'facturapi_folio' => 128,
        'uuid_fiscal' => '11111111-2222-3333-4444-555555555555',
        'sello_cfdi' => str_repeat('A', 344),
        'sello_sat' => str_repeat('B', 344),
        'no_certificado_sat' => '30001000000400002495',
        'cadena_original_sat' => '||1.1|11111111-2222-3333-4444-555555555555|2026-08-12T10:00:00|AAA010101AAA & Cía|'.str_repeat('C', 200).'||',
        'fecha_timbrado' => now(),
    ], $overrides));

    $factura->lineas()->create([
        'articulo_id' => articuloParaPdf($user)->id,
        'cantidad' => 2,
        'descripcion' => 'Sello de goma',
        'modelo' => 'MOD-4321',
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);

    return $factura->fresh();
}

function cotizacionConLinea(User $user): Cotizacion
{
    $cliente = Cliente::factory()->for($user)->create(['razon_social' => 'Clientes Unidos SA de CV']);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create();

    $cotizacion->lineas()->create([
        'articulo_id' => articuloParaPdf($user)->id,
        'cantidad' => 2,
        'descripcion' => 'Sello de goma',
        'modelo' => 'MOD-4321',
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);

    return $cotizacion->fresh();
}

function ordenConLinea(User $user, array $overridesProveedor = []): OrdenCompra
{
    $proveedor = Proveedor::factory()->for($user)->create(array_merge([
        'nombre_comercial' => 'Distribuidora Ejemplo SA de CV',
    ], $overridesProveedor));

    $orden = OrdenCompra::factory()->for($user)->for($proveedor)->create();

    $orden->lineas()->create([
        'articulo_id' => articuloParaPdf($user)->id,
        'cantidad' => 2,
        'descripcion' => 'Sello de goma',
        'modelo' => 'MOD-4321',
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);

    return $orden->fresh();
}

test('los tres documentos imprimen los datos del emisor y su folio', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $factura = facturaTimbrada($user);
    $cotizacion = cotizacionConLinea($user);
    $orden = ordenConLinea($user);

    $htmls = [
        view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render(),
        view('pdf.cotizacion', $cotizacion->datosPdf())->render(),
        view('pdf.orden-compra', $orden->datosPdf())->render(),
    ];

    foreach ($htmls as $html) {
        expect($html)
            ->toContain('ABDIAS REYES REYNA')
            ->toContain('RERA7701272R1')
            // La descripción sale del catálogo SAT, no de una lista escrita a mano.
            ->toContain('612 - Personas Físicas con Actividades Empresariales y Profesionales');
    }

    expect($htmls[0])->toContain('FACTURA')->toContain('F128');
    expect($htmls[1])->toContain('COTIZACIÓN')->toContain((string) $cotizacion->folio);
    expect($htmls[2])->toContain('ORDEN DE COMPRA')->toContain($orden->folioFormateado());
});

test('los tres documentos se generan aunque el emisor este vacio', function () {
    $user = User::factory()->create();

    $this->assertDatabaseCount('emisor', 0);

    $factura = facturaTimbrada($user);
    $cotizacion = cotizacionConLinea($user);
    $orden = ordenConLinea($user);

    $this->actingAs($user)->get("/api/v1/facturas/{$factura->id}/pdf")->assertOk();
    $this->actingAs($user)->get("/api/v1/cotizaciones/{$cotizacion->id}/pdf")->assertOk();
    $this->actingAs($user)->get("/api/v1/ordenes-compra/{$orden->id}/pdf")->assertOk();

    expect(view('pdf.cotizacion', $cotizacion->datosPdf())->render())
        ->toContain('Sin datos fiscales capturados');
});

test('los tres documentos se generan sin logos cargados', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $factura = facturaTimbrada($user);
    $cotizacion = cotizacionConLinea($user);
    $orden = ordenConLinea($user);

    $respuesta = $this->actingAs($user)->get("/api/v1/facturas/{$factura->id}/pdf");
    $respuesta->assertOk();
    expect(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');

    $this->actingAs($user)->get("/api/v1/cotizaciones/{$cotizacion->id}/pdf")->assertOk();
    $this->actingAs($user)->get("/api/v1/ordenes-compra/{$orden->id}/pdf")->assertOk();
});

test('una factura timbrada imprime uuid sellos y cadena original', function () {
    $user = User::factory()->create();
    emisorCompleto();
    $factura = facturaTimbrada($user);

    $html = view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render();

    expect($html)
        ->toContain('Timbre Fiscal Digital')
        ->toContain('11111111-2222-3333-4444-555555555555')
        ->toContain('30001000000400002495')
        ->toContain('Sello CFDI')
        ->toContain('Sello SAT')
        ->toContain('Cadena original')
        // Se dibuja aquí mismo: nada se descarga de un servicio ajeno.
        ->toContain('data:image/png;base64,');
});

test('una factura sin timbrar no lleva bloque de timbre y se genera igual', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $factura = facturaTimbrada($user, [
        'estado' => EstadoFactura::Pendiente->value,
        'uuid_fiscal' => null,
        'sello_cfdi' => null,
        'sello_sat' => null,
        'cadena_original_sat' => null,
        'no_certificado_sat' => null,
        'fecha_timbrado' => null,
        'facturapi_serie' => null,
        'facturapi_folio' => null,
    ]);

    $html = view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render();

    expect($html)
        ->not->toContain('Timbre Fiscal Digital')
        ->toContain('FACTURA')
        ->toContain('Vigente');
});

test('una factura cancelada conserva su timbre y marca el estado en rojo', function () {
    $user = User::factory()->create();
    emisorCompleto();
    $factura = facturaTimbrada($user, ['estado' => EstadoFactura::Cancelada->value]);

    $html = view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render();

    expect($html)
        ->toContain('Timbre Fiscal Digital')
        ->toContain('Cancelada')
        ->toContain('class="cancelado"');
});

test('una orden de un proveedor sin contacto sin rfc y sin correo se genera igual', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $orden = ordenConLinea($user, [
        'rfc' => null,
        'nombre_contacto' => null,
        'correo' => null,
        'telefono' => null,
    ]);

    $this->actingAs($user)->get("/api/v1/ordenes-compra/{$orden->id}/pdf")->assertOk();

    expect(view('pdf.orden-compra', $orden->datosPdf())->render())
        ->toContain('Distribuidora Ejemplo SA de CV')
        ->toContain('Costo unitario');
});

test('una linea de texto libre imprime la clave sat vacia', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $cotizacion = cotizacionConLinea($user);
    $cotizacion->lineas()->create([
        'articulo_id' => null,
        'cantidad' => 1,
        'descripcion' => 'Servicio de diseño',
        'modelo' => 'N/A',
        'precio_unitario' => 500.00,
        'tasa_iva' => '16',
        'importe' => 500.00,
        'iva_importe' => 80.00,
    ]);

    $html = view('pdf.cotizacion', $cotizacion->fresh()->datosPdf())->render();

    expect($html)
        ->toContain('Servicio de diseño')
        // La única clave impresa es la de la línea con artículo.
        ->toContain('44121801');

    expect(substr_count($html, '44121801'))->toBe(1);
});

test('una linea de un articulo borrado conserva su clave sat', function () {
    $user = User::factory()->create();
    emisorCompleto();

    $cotizacion = cotizacionConLinea($user);
    Articulo::query()->where('user_id', $user->id)->delete();

    expect(view('pdf.cotizacion', $cotizacion->fresh()->datosPdf())->render())
        ->toContain('44121801');
});

test('la direccion de verificacion del sat lleva los cinco parametros', function () {
    $user = User::factory()->create();
    $emisor = emisorCompleto();
    $factura = facturaTimbrada($user, ['total' => 232.00]);

    $url = app(QrTimbreFiscal::class)->url($factura->load('cliente'), $emisor);

    expect($url)
        ->toContain('https://verificacfdi.facturaelectronica.sat.gob.mx/default.aspx')
        ->toContain('id=11111111-2222-3333-4444-555555555555')
        ->toContain('re=RERA7701272R1')
        // Codificado, no crudo: un RFC con Ñ dentro de una URL sin escapar da una dirección
        // inválida. La concatenación a mano del formato de referencia no lo contemplaba.
        ->toContain('rr='.urlencode($factura->cliente->rfc))
        // Seis decimales y sin separador de miles, como pide el Anexo 20.
        ->toContain('tt=232.000000')
        ->toContain('fe='.substr($factura->sello_cfdi, -8));
});

test('sin rfc del emisor el pdf sale con la direccion en texto y deja rastro en el log', function () {
    $user = User::factory()->create();
    $factura = facturaTimbrada($user);

    Log::shouldReceive('error')->once()->withAnyArgs();
    Log::shouldReceive('warning')->zeroOrMoreTimes()->withAnyArgs();

    $html = view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render();

    // El bloque sigue ahí y el comprobante sigue siendo verificable, solo que sin imagen.
    expect($html)
        ->toContain('Timbre Fiscal Digital')
        ->not->toContain('data:image/png;base64,');
});

test('la caja monoespaciada corta el texto sin espacios y escapa las entidades', function () {
    $texto = str_repeat('X', 250).'&'.str_repeat('Y', 250);

    $html = (string) view('components.pdf.mono-box', ['texto' => $texto]);

    // La oportunidad de corte aparece cada 120 caracteres, que es lo único que permite que una
    // tira sin espacios no se salga de la hoja.
    expect($html)->toContain(str_repeat('X', 120)."\n");

    // El corte fue ANTES del escapado, así que la entidad nunca queda partida.
    expect($html)->toContain('&amp;');
    expect($html)->not->toContain("&am\n");
    expect($html)->not->toContain("&\namp;");
});

test('guardar el emisor dos veces no crea dos registros', function () {
    $user = User::factory()->create();

    $datos = [
        'nombre' => 'ABDIAS REYES REYNA',
        'rfc' => 'RERA7701272R1',
        'regimen_fiscal' => '612',
    ];

    // La primera vez el emisor nace (201); a partir de ahí siempre se actualiza el mismo (200).
    $this->actingAs($user)->putJson('/api/v1/emisor', $datos)->assertCreated();
    $this->actingAs($user)
        ->putJson('/api/v1/emisor', [...$datos, 'domicilio' => 'Celaya, Guanajuato'])
        ->assertOk()
        ->assertJson(['data' => ['domicilio' => 'Celaya, Guanajuato', 'esta_completo' => true]]);

    $this->assertDatabaseCount('emisor', 1);
});

test('un rfc invalido del emisor se rechaza', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/emisor', [
            'nombre' => 'Quien sea',
            'rfc' => 'NO-ES-UN-RFC',
            'regimen_fiscal' => '612',
        ])
        ->assertStatus(422);

    $this->assertDatabaseCount('emisor', 0);
});

test('subir un logo lo guarda borra el anterior y se puede eliminar', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    emisorCompleto();

    $this->actingAs($user)
        ->postJson('/api/v1/emisor/logo', [
            'tipo' => 'principal',
            'archivo' => UploadedFile::fake()->image('logo.png', 300, 100),
        ])
        ->assertOk()
        ->assertJson(['data' => ['tiene_logo' => true]]);

    $primera = Emisor::actual()->logo_ruta;
    Storage::disk('local')->assertExists($primera);

    $this->actingAs($user)
        ->postJson('/api/v1/emisor/logo', [
            'tipo' => 'principal',
            'archivo' => UploadedFile::fake()->image('otro.png', 300, 100),
        ])
        ->assertOk();

    Storage::disk('local')->assertMissing($primera);
    Storage::disk('local')->assertExists(Emisor::actual()->logo_ruta);

    $this->actingAs($user)->deleteJson('/api/v1/emisor/logo/principal')->assertOk();

    expect(Emisor::actual()->logo_ruta)->toBeNull();
});

test('se puede subir un logo antes de capturar los datos fiscales', function () {
    Storage::fake('local');
    $user = User::factory()->create();

    // El emisor se llena por partes y en el orden que el usuario quiera. Empezar por el logo es
    // razonable, y con las columnas fiscales NOT NULL esa fila no se podía ni crear.
    $this->assertDatabaseCount('emisor', 0);

    $this->actingAs($user)
        ->postJson('/api/v1/emisor/logo', [
            'tipo' => 'marca',
            'archivo' => UploadedFile::fake()->image('marca.png', 300, 100),
        ])
        ->assertSuccessful()
        ->assertJson(['data' => ['tiene_logo_marca' => true, 'esta_completo' => false]]);

    Storage::disk('local')->assertExists(Emisor::actual()->logo_marca_ruta);
});

test('un svg o un archivo demasiado grande se rechazan', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/emisor/logo', [
            'tipo' => 'principal',
            'archivo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])
        ->assertStatus(422);

    $this->actingAs($user)
        ->postJson('/api/v1/emisor/logo', [
            'tipo' => 'principal',
            'archivo' => UploadedFile::fake()->image('enorme.png')->size(3000),
        ])
        ->assertStatus(422);
});

test('el pdf que se descarga para compartir tambien lleva el emisor', function () {
    $user = User::factory()->create();
    emisorCompleto();
    $cotizacion = cotizacionConLinea($user);

    // Es el archivo que el frontend baja para pasárselo al menú de compartir del aparato (ver
    // 029-pwa-mostrador.md). El emisor entra por composer justamente para que ninguna de las dos
    // salidas del PDF quede sin encabezado.
    $respuesta = $this->actingAs($user)->get("/api/v1/cotizaciones/{$cotizacion->id}/pdf");

    $respuesta->assertOk();
    expect(substr($respuesta->getContent(), 0, 4))->toBe('%PDF');
});
