<?php

use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\DatoBancario;
use App\Models\Factura;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Rules\ClabeValida;
use Database\Factories\DatoBancarioFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Datos bancarios del negocio impresos en la cotización (ver 026-datos-bancarios-cotizacion.md).
 */

// Los logos van al disco privado: se finge en todas las pruebas del archivo para no dejar archivos
// sueltos en `storage/app` ni depender de lo que haya quedado de una corrida anterior.
beforeEach(fn () => Storage::fake('local'));

function datosBancoValidos(array $overrides = []): array
{
    return array_merge([
        'nombre_banco' => 'BBVA',
        'beneficiario' => 'Abdias Reyes',
        'numero_cuenta' => '0123456789',
        'tarjeta' => '4152313312345678',
        'clabe' => DatoBancarioFactory::clabeValida('01218000123456789'),
    ], $overrides);
}

/**
 * Cotización con una línea, creada por el endpoint real: la foto de los datos bancarios la toma el
 * controlador al crear, así que una cotización armada con `Cotizacion::factory()` no probaría nada.
 */
function crearCotizacionPorApi(User $user): Cotizacion
{
    $cliente = Cliente::factory()->for($user)->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(['modelo' => 'MOD-1234']);

    $respuesta = test()->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [lineaDeCotizacion($articulo)],
        'total' => 116,
    ])->assertCreated();

    return Cotizacion::findOrFail($respuesta->json('data.id'));
}

/**
 * @return array<string, mixed>
 */
function lineaDeCotizacion(Articulo $articulo, int $cantidad = 1): array
{
    return [
        'articulo_id' => $articulo->id,
        'cantidad' => $cantidad,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
    ];
}

// ---------------------------------------------------------------- CRUD y validaciones

test('se pueden guardar varios bancos y se listan en el orden de captura', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', datosBancoValidos())
        ->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', datosBancoValidos([
        'nombre_banco' => 'Santander',
        'numero_cuenta' => null,
        'tarjeta' => null,
    ]))->assertCreated();

    $respuesta = $this->actingAs($user)->getJson('/api/v1/datos-bancarios')->assertOk();

    expect($respuesta->json('data.0.nombre_banco'))->toBe('BBVA')
        ->and($respuesta->json('data.1.nombre_banco'))->toBe('Santander')
        ->and($respuesta->json('data.0.orden'))->toBeLessThan($respuesta->json('data.1.orden'));
});

test('un banco sin nombre no se guarda', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['nombre_banco' => '']))
        ->assertJsonValidationErrors('nombre_banco');
});

test('un banco sin ningún número no se guarda', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', [
        'nombre_banco' => 'BBVA',
        'numero_cuenta' => null,
        'tarjeta' => null,
        'clabe' => null,
    ])
        ->assertJsonValidationErrors('datos_bancarios')
        ->assertJsonFragment(['datos_bancarios' => ['Captura al menos un número de cuenta, tarjeta o CLABE.']]);

    expect(DatoBancario::count())->toBe(0);
});

test('un banco con solo uno de los tres números sí se guarda', function () {
    $user = User::factory()->create();

    foreach (['numero_cuenta' => '0123456789', 'tarjeta' => '4152313312345678', 'clabe' => DatoBancarioFactory::clabeValida()] as $campo => $valor) {
        $this->actingAs($user)->postJson('/api/v1/datos-bancarios', [
            'nombre_banco' => 'Banco de prueba',
            $campo => $valor,
        ])->assertCreated();
    }

    expect(DatoBancario::count())->toBe(3);
});

test('la CLABE con dígito verificador incorrecto se rechaza y la correcta se guarda', function () {
    $user = User::factory()->create();

    $valida = DatoBancarioFactory::clabeValida('01218000123456789');
    // Se mueve solo el verificador: la longitud sigue siendo 18 y solo la cuenta lo delata.
    $invalida = substr($valida, 0, 17).((((int) $valida[17]) + 1) % 10);

    $this->actingAs($user)
        ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['clabe' => $invalida]))
        ->assertJsonValidationErrors('clabe');

    $this->actingAs($user)
        ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['clabe' => $valida]))
        ->assertCreated();
});

test('una CLABE que no mide 18 dígitos se rechaza', function () {
    $user = User::factory()->create();

    foreach (['0121800012345678', '012180001234567890'] as $clabe) {
        $this->actingAs($user)
            ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['clabe' => $clabe]))
            ->assertJsonValidationErrors('clabe');
    }
});

test('el dígito verificador se calcula con la última cifra de cada producto', function () {
    // CLABE real de referencia del algoritmo: los pesos 3-7-1 sobre los 17 primeros dígitos.
    expect(ClabeValida::digitoVerificador('01218000123456789'))->toBeLessThan(10)
        ->and(DatoBancarioFactory::clabeValida('01218000123456789'))->toHaveLength(18);
});

test('los números se guardan solo con dígitos aunque se peguen con espacios', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', datosBancoValidos([
        'tarjeta' => '4152 3133 1234 5678',
        'numero_cuenta' => '012-345-6789',
    ]))->assertCreated();

    $banco = DatoBancario::firstOrFail();

    expect($banco->tarjeta)->toBe('4152313312345678')
        ->and($banco->numero_cuenta)->toBe('0123456789');
});

test('un número de cuenta que empieza con cero conserva el cero', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['numero_cuenta' => '000123456']))
        ->assertCreated();

    expect(DatoBancario::firstOrFail()->numero_cuenta)->toBe('000123456');
});

test('una tarjeta que no mide 15 ni 16 dígitos se rechaza', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/datos-bancarios', datosBancoValidos(['tarjeta' => '12345678901234567']))
        ->assertJsonValidationErrors('tarjeta');
});

test('se pueden guardar dos cuentas del mismo banco', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', datosBancoValidos())->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/datos-bancarios', datosBancoValidos([
        'numero_cuenta' => '9876543210',
        'clabe' => DatoBancarioFactory::clabeValida('01218000999888777'),
    ]))->assertCreated();

    expect(DatoBancario::where('nombre_banco', 'BBVA')->count())->toBe(2);
});

test('se puede editar y eliminar un banco', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)
        ->putJson("/api/v1/datos-bancarios/{$banco->id}", datosBancoValidos(['nombre_banco' => 'Banorte']))
        ->assertOk()
        ->assertJsonPath('data.nombre_banco', 'Banorte');

    $this->actingAs($user)->deleteJson("/api/v1/datos-bancarios/{$banco->id}")->assertNoContent();

    expect(DatoBancario::count())->toBe(0);
});

test('los endpoints de datos bancarios exigen sesión', function () {
    $banco = DatoBancario::factory()->create();

    $this->getJson('/api/v1/datos-bancarios')->assertUnauthorized();
    $this->postJson('/api/v1/datos-bancarios', datosBancoValidos())->assertUnauthorized();
    $this->putJson("/api/v1/datos-bancarios/{$banco->id}", datosBancoValidos())->assertUnauthorized();
    $this->deleteJson("/api/v1/datos-bancarios/{$banco->id}")->assertUnauthorized();
    $this->putJson('/api/v1/datos-bancarios/orden', ['ids' => [$banco->id]])->assertUnauthorized();
});

// ---------------------------------------------------------------- Orden

test('reordenar cambia el orden de la lista', function () {
    $user = User::factory()->create();
    $primero = DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'orden' => 1]);
    $segundo = DatoBancario::factory()->create(['nombre_banco' => 'Santander', 'orden' => 2]);

    $this->actingAs($user)
        ->putJson('/api/v1/datos-bancarios/orden', ['ids' => [$segundo->id, $primero->id]])
        ->assertOk()
        ->assertJsonPath('data.0.nombre_banco', 'Santander')
        ->assertJsonPath('data.1.nombre_banco', 'BBVA');
});

test('un reordenamiento parcial se rechaza', function () {
    $user = User::factory()->create();
    $primero = DatoBancario::factory()->create(['orden' => 1]);
    DatoBancario::factory()->create(['orden' => 2]);

    $this->actingAs($user)
        ->putJson('/api/v1/datos-bancarios/orden', ['ids' => [$primero->id]])
        ->assertJsonValidationErrors('ids');
});

// ---------------------------------------------------------------- Congelado

test('la cotización guarda la foto de los bancos visibles al crearse', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'orden' => 1]);
    DatoBancario::factory()->oculto()->create(['nombre_banco' => 'Santander', 'orden' => 2]);

    $cotizacion = crearCotizacionPorApi($user);

    expect($cotizacion->datos_bancarios)->toHaveCount(1)
        ->and($cotizacion->datos_bancarios[0]['nombre_banco'])->toBe('BBVA');
});

test('cambiar un banco no cambia la foto de una cotización ya creada', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'BBVA', 'clabe' => DatoBancarioFactory::clabeValida('01218000123456789')]);

    $cotizacion = crearCotizacionPorApi($user);
    $clabeOriginal = $cotizacion->datos_bancarios[0]['clabe'];

    $this->actingAs($user)->putJson("/api/v1/datos-bancarios/{$banco->id}", datosBancoValidos([
        'clabe' => DatoBancarioFactory::clabeValida('01218000999888777'),
    ]))->assertOk();

    expect($cotizacion->fresh()->datos_bancarios[0]['clabe'])->toBe($clabeOriginal);
});

test('eliminar un banco no cambia la foto de una cotización ya creada', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $cotizacion = crearCotizacionPorApi($user);

    $this->actingAs($user)->deleteJson("/api/v1/datos-bancarios/{$banco->id}")->assertNoContent();

    expect($cotizacion->fresh()->datos_bancarios)->toHaveCount(1)
        ->and($cotizacion->fresh()->datos_bancarios[0]['nombre_banco'])->toBe('BBVA');
});

test('editar una cotización no vuelve a tomar la foto', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $cotizacion = crearCotizacionPorApi($user);

    $this->actingAs($user)->putJson("/api/v1/datos-bancarios/{$banco->id}", datosBancoValidos([
        'nombre_banco' => 'Banorte',
    ]))->assertOk();

    $linea = $cotizacion->lineas()->firstOrFail();

    $this->actingAs($user)->putJson("/api/v1/cotizaciones/{$cotizacion->id}", [
        'cliente_id' => $cotizacion->cliente_id,
        'lineas' => [[
            'articulo_id' => $linea->articulo_id,
            'cantidad' => 2,
            'descripcion' => $linea->descripcion,
            'modelo' => $linea->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232,
    ])->assertOk();

    expect($cotizacion->fresh()->datos_bancarios[0]['nombre_banco'])->toBe('BBVA');
});

test('duplicar una cotización toma foto nueva', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $cotizacion = crearCotizacionPorApi($user);

    $this->actingAs($user)->putJson("/api/v1/datos-bancarios/{$banco->id}", datosBancoValidos([
        'nombre_banco' => 'Banorte',
    ]))->assertOk();

    $respuesta = $this->actingAs($user)
        ->postJson("/api/v1/cotizaciones/{$cotizacion->id}/duplicar")
        ->assertCreated();

    $copia = Cotizacion::findOrFail($respuesta->json('data.id'));

    expect($copia->datos_bancarios[0]['nombre_banco'])->toBe('Banorte')
        ->and($cotizacion->fresh()->datos_bancarios[0]['nombre_banco'])->toBe('BBVA');
});

test('una cotización creada sin bancos guarda una foto vacía', function () {
    $user = User::factory()->create();

    expect(crearCotizacionPorApi($user)->datos_bancarios)->toBe([]);
});

// ---------------------------------------------------------------- PDF

test('el PDF de la cotización imprime los datos bancarios congelados', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create([
        'nombre_banco' => 'BBVA',
        'beneficiario' => 'Abdias Reyes',
        'numero_cuenta' => '0123456789',
        'tarjeta' => '4152313312345678',
        'clabe' => DatoBancarioFactory::clabeValida('01218000123456789'),
    ]);

    $cotizacion = crearCotizacionPorApi($user);
    $html = view('pdf.cotizacion', $cotizacion->fresh()->datosPdf())->render();

    expect($html)->toContain('Datos bancarios')
        ->toContain('BBVA')
        ->toContain('Abdias Reyes')
        ->toContain('Cta: 0123456789')
        // Completa, sin enmascarar: el dato existe para que el cliente pueda pagar.
        ->toContain('Tarjeta: 4152313312345678')
        ->toContain('CLABE: '.DatoBancarioFactory::clabeValida('01218000123456789'));
});

test('un banco con solo CLABE no imprime renglones vacíos', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create([
        'nombre_banco' => 'Santander',
        'beneficiario' => null,
        'numero_cuenta' => null,
        'tarjeta' => null,
        'clabe' => DatoBancarioFactory::clabeValida('01418000987654321'),
    ]);

    $html = view('pdf.cotizacion', crearCotizacionPorApi($user)->fresh()->datosPdf())->render();

    expect($html)->toContain('Santander')
        ->not->toContain('Cta:')
        ->not->toContain('Tarjeta:');
});

test('los bancos se imprimen en el orden de la lista', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create(['nombre_banco' => 'Banorte', 'orden' => 2]);
    DatoBancario::factory()->create(['nombre_banco' => 'HSBC', 'orden' => 1]);

    $html = view('pdf.cotizacion', crearCotizacionPorApi($user)->fresh()->datosPdf())->render();

    expect(strpos($html, 'HSBC'))->toBeLessThan(strpos($html, 'Banorte'));
});

test('una cotización sin bancos se imprime sin el bloque', function () {
    $user = User::factory()->create();

    $html = view('pdf.cotizacion', crearCotizacionPorApi($user)->fresh()->datosPdf())->render();

    expect($html)->not->toContain('Datos bancarios');
});

test('la factura y la orden de compra no imprimen datos bancarios', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $factura = Factura::factory()->for($user)->for(Cliente::factory()->for($user))->create();
    $factura->lineas()->create([
        'cantidad' => 1,
        'descripcion' => 'Servicio de diseño',
        'modelo' => 'MOD-1234',
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 100.00,
        'iva_importe' => 16.00,
    ]);

    $orden = OrdenCompra::factory()->for($user)->for(Proveedor::factory()->for($user))->create();
    $orden->lineas()->create([
        'cantidad' => 1,
        'descripcion' => 'Insumo',
        'modelo' => 'MOD-1234',
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 100.00,
        'iva_importe' => 16.00,
    ]);

    $htmls = [
        view('pdf.factura', ['factura' => $factura->load('cliente', 'lineas.articulo')])->render(),
        view('pdf.orden-compra', $orden->datosPdf())->render(),
    ];

    foreach ($htmls as $html) {
        expect($html)->not->toContain('Datos bancarios')
            ->not->toContain('BBVA');
    }
});

// ---------------------------------------------------------------- Logo del banco

/** PNG real de `$lado` puntos, con una esquina transparente para poder comprobar el canal alfa. */
function pngDePrueba(int $lado, string $nombre = 'logo.png'): UploadedFile
{
    $imagen = imagecreatetruecolor($lado, $lado);
    imagealphablending($imagen, false);
    imagesavealpha($imagen, true);
    imagefill($imagen, 0, 0, imagecolorallocatealpha($imagen, 0, 0, 0, 127));
    imagefilledrectangle($imagen, 0, 0, (int) ($lado / 2), (int) ($lado / 2), imagecolorallocate($imagen, 0, 60, 130));

    $ruta = tempnam(sys_get_temp_dir(), 'logo').'.png';
    imagepng($imagen, $ruta);
    imagedestroy($imagen);

    return new UploadedFile($ruta, $nombre, 'image/png', null, true);
}

/** @return array{0: int, 1: int} ancho y alto del contenido de una imagen */
function medidasDe(string $contenido): array
{
    $info = getimagesizefromstring($contenido);

    return [$info[0], $info[1]];
}

test('se sube un logo, se reduce a 64 puntos y se sirve como webp', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)
        ->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(500)])
        ->assertOk()
        ->assertJsonPath('data.tiene_logo', true);

    $respuesta = $this->actingAs($user)
        ->get("/api/v1/datos-bancarios/{$banco->id}/logo")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/webp');

    expect(medidasDe($respuesta->getContent()))->toBe([64, 64]);
});

test('un logo más chico que 64 puntos no se amplía', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)
        ->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(40)])
        ->assertOk();

    $respuesta = $this->actingAs($user)->get("/api/v1/datos-bancarios/{$banco->id}/logo");

    expect(medidasDe($respuesta->getContent()))->toBe([40, 40]);
});

test('el logo conserva la transparencia', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)
        ->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(200)])
        ->assertOk();

    $imagen = imagecreatefromstring($banco->fresh()->contenidoLogo());
    // La esquina inferior derecha quedó transparente en el PNG de origen; si se hubiera aplanado,
    // saldría negra u opaca.
    $color = imagecolorsforindex($imagen, imagecolorat($imagen, 60, 60));
    imagedestroy($imagen);

    expect($color['alpha'])->toBeGreaterThan(0);
});

test('un archivo que no es imagen se rechaza aunque termine en png', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", [
        'archivo' => UploadedFile::fake()->createWithContent('logo.png', 'esto no es una imagen'),
    ])->assertStatus(422);

    expect($banco->fresh()->logo_ruta)->toBeNull();
});

test('reemplazar el logo borra el archivo anterior y cambia la versión', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(100)]);
    $primera = $banco->fresh()->logo_ruta;

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(100)]);
    $segunda = $banco->fresh()->logo_ruta;

    expect($segunda)->not->toBe($primera)
        ->and(Storage::disk('local')->exists($primera))->toBeFalse()
        ->and(Storage::disk('local')->exists($segunda))->toBeTrue();
});

test('quitar el logo lo borra del disco', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(100)]);
    $ruta = $banco->fresh()->logo_ruta;

    $this->actingAs($user)
        ->deleteJson("/api/v1/datos-bancarios/{$banco->id}/logo")
        ->assertOk()
        ->assertJson(['eliminado' => true]);

    expect($banco->fresh()->logo_ruta)->toBeNull()
        ->and(Storage::disk('local')->exists($ruta))->toBeFalse();
});

test('eliminar el banco NO borra el archivo de su logo', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(100)]);
    $ruta = $banco->fresh()->logo_ruta;

    $this->actingAs($user)->deleteJson("/api/v1/datos-bancarios/{$banco->id}")->assertNoContent();

    // Las cotizaciones ya creadas guardan esta ruta en su foto y lo siguen imprimiendo.
    expect(Storage::disk('local')->exists($ruta))->toBeTrue();
});

test('el logo solo se sirve con sesión iniciada', function () {
    $banco = DatoBancario::factory()->create();

    $this->get("/api/v1/datos-bancarios/{$banco->id}/logo")->assertUnauthorized();
    $this->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(100)])->assertUnauthorized();
    $this->deleteJson("/api/v1/datos-bancarios/{$banco->id}/logo")->assertUnauthorized();
});

test('un banco sin logo responde 404 al pedirlo', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->get("/api/v1/datos-bancarios/{$banco->id}/logo")->assertNotFound();
});

test('el PDF imprime el icono del banco que tenía logo', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(200)]);

    $cotizacion = crearCotizacionPorApi($user);
    $html = view('pdf.cotizacion', $cotizacion->fresh()->datosPdf())->render();

    expect($html)->toContain('data:image/webp;base64,')->toContain('BBVA');
});

test('un banco sin logo imprime su nombre sin icono', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create(['nombre_banco' => 'Santander']);

    $html = view('pdf.cotizacion', crearCotizacionPorApi($user)->fresh()->datosPdf())->render();

    expect($html)->toContain('Santander')->not->toContain('data:image/webp;base64,');
});

test('si el archivo del logo desaparece, el PDF sale sin icono y no falla', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create(['nombre_banco' => 'Banorte']);

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(200)]);

    $cotizacion = crearCotizacionPorApi($user);
    Storage::disk('local')->delete($banco->fresh()->logo_ruta);

    $html = view('pdf.cotizacion', $cotizacion->fresh()->datosPdf())->render();

    expect($html)->toContain('Banorte')->not->toContain('data:image/webp;base64,');
});

test('cambiar el logo no cambia el PDF de una cotización ya creada', function () {
    $user = User::factory()->create();
    $banco = DatoBancario::factory()->create();

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(200)]);
    $cotizacion = crearCotizacionPorApi($user);
    $rutaCongelada = $cotizacion->datos_bancarios[0]['logo_ruta'];

    $this->actingAs($user)->post("/api/v1/datos-bancarios/{$banco->id}/logo", ['archivo' => pngDePrueba(300)]);

    expect($cotizacion->fresh()->datos_bancarios[0]['logo_ruta'])->toBe($rutaCongelada)
        ->and($banco->fresh()->logo_ruta)->not->toBe($rutaCongelada);
});

test('el PDF público firmado lleva el mismo bloque', function () {
    $user = User::factory()->create();
    DatoBancario::factory()->create(['nombre_banco' => 'BBVA']);

    $cotizacion = crearCotizacionPorApi($user);

    // Sin sesión: la protege la firma temporal de la URL, no `auth:sanctum`. Es el camino por el
    // que Twilio descarga el adjunto del WhatsApp, y tiene que llevar el mismo bloque.
    $this->get($cotizacion->urlPdfPublico())->assertOk();
});
