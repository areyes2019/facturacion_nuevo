<?php

use App\Enums\EstadoOrdenTrabajo;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\OrdenTrabajo;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Producción: Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 */
function articuloParaOrdenTrabajo(User $user): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    return Articulo::factory()->for($user)->for($catalogo)->create([
        'nombre' => 'Sello personalizado',
        'precio_unitario_sin_iva' => 100.00,
        'existencia' => 10,
    ]);
}

/** Un pedido con folio, líneas y un pago registrado (no necesita estar pagado por completo). */
function pedidoConPago(User $user, float $monto = 100.00): Pedido
{
    $articulo = articuloParaOrdenTrabajo($user);
    $cuenta = Cuenta::factory()->for($user)->create();

    test()->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Juan Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => null,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ])->assertCreated();

    $pedido = Pedido::where('user_id', $user->id)->latest('id')->firstOrFail();

    test()->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => $monto,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    return $pedido->fresh();
}

function crearOrdenDesdePedido(User $user, Pedido $pedido): OrdenTrabajo
{
    test()->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ])->assertCreated();

    return OrdenTrabajo::where('documentable_id', $pedido->id)->where('documentable_type', Pedido::class)->firstOrFail();
}

test('un invitado no puede acceder a ordenes de trabajo', function () {
    $this->getJson('/api/v1/ordenes-trabajo')->assertUnauthorized();
});

test('no se puede crear una orden de trabajo para un pedido sin pagos', function () {
    $user = User::factory()->create();
    $articulo = articuloParaOrdenTrabajo($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'Juan Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => null,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 1,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 116.00,
    ])->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('documentable_id');
});

test('crear una orden de trabajo desde un pedido lee cliente, producto y saldo sin duplicarlos', function () {
    $user = User::factory()->create();
    $pedido = pedidoConPago($user, 100.00);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'pendiente');
    $response->assertJsonPath('data.folio', 1);
    $response->assertJsonPath('data.folio_formateado', 'OT-00001');
    $response->assertJsonPath('data.documentable_type', 'pedido');
    $response->assertJsonPath('data.cliente_nombre', 'Juan Pérez');
    $response->assertJsonPath('data.saldo_pendiente', 132);
});

test('un documento no puede tener dos ordenes de trabajo', function () {
    $user = User::factory()->create();
    $pedido = pedidoConPago($user);
    crearOrdenDesdePedido($user, $pedido);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('documentable_id');
});

test('crear una orden de trabajo desde una cotizacion lee el cliente del catalogo', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create(['razon_social' => 'Comercializadora Ejemplo SA de CV']);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'cotizacion',
        'documentable_id' => $cotizacion->id,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.documentable_type', 'cotizacion');
    $response->assertJsonPath('data.cliente_nombre', 'Comercializadora Ejemplo SA de CV');
});

test('no se puede usar el documento de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    $pedido = pedidoConPago($otro);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'pedido',
        'documentable_id' => $pedido->id,
    ]);

    $response->assertUnprocessable();
});

test('iniciar produccion solo funciona desde pendiente', function () {
    $user = User::factory()->create();
    $orden = crearOrdenDesdePedido($user, pedidoConPago($user));

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/iniciar-produccion")
        ->assertOk()
        ->assertJsonPath('data.estado', 'en_produccion');

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/iniciar-produccion")
        ->assertStatus(422);
});

test('marcar listo solo funciona desde en produccion', function () {
    $user = User::factory()->create();
    $orden = crearOrdenDesdePedido($user, pedidoConPago($user));

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/marcar-listo")
        ->assertStatus(422);

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/iniciar-produccion")->assertOk();
    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/marcar-listo")
        ->assertOk()
        ->assertJsonPath('data.estado', 'listo_para_entregar');
});

test('subir una imagen nueva reemplaza la anterior y borra el archivo viejo', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $orden = crearOrdenDesdePedido($user, pedidoConPago($user));

    $primera = UploadedFile::fake()->image('diseno1.png', 50, 50);
    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/imagen", ['archivo' => $primera])
        ->assertOk();

    $rutaAnterior = $orden->fresh()->imagen_ruta;
    Storage::disk('local')->assertExists($rutaAnterior);

    $segunda = UploadedFile::fake()->image('diseno2.png', 50, 50);
    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/imagen", ['archivo' => $segunda])
        ->assertOk();

    $rutaNueva = $orden->fresh()->imagen_ruta;
    expect($rutaNueva)->not->toBe($rutaAnterior);
    Storage::disk('local')->assertMissing($rutaAnterior);
    Storage::disk('local')->assertExists($rutaNueva);
});

test('el listado excluye entregadas por defecto y las muestra al filtrar', function () {
    $user = User::factory()->create();
    $entregada = crearOrdenDesdePedido($user, pedidoConPago($user));
    $entregada->update(['estado' => EstadoOrdenTrabajo::Entregado->value]);
    $pendiente = crearOrdenDesdePedido($user, pedidoConPago($user));

    $response = $this->actingAs($user)->getJson('/api/v1/ordenes-trabajo');
    $response->assertOk();
    $ids = collect($response->json('data'))->pluck('id');
    expect($ids)->toContain($pendiente->id);
    expect($ids)->not->toContain($entregada->id);

    $response = $this->actingAs($user)->getJson('/api/v1/ordenes-trabajo?estado=entregado');
    expect(collect($response->json('data'))->pluck('id'))->toContain($entregada->id);
});

test('escanear el qr del pedido marca entregada la orden de trabajo vinculada', function () {
    $user = User::factory()->create();
    $pedido = pedidoConPago($user, 232.00);
    $orden = crearOrdenDesdePedido($user, $pedido);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();

    expect($orden->fresh()->estado)->toBe(EstadoOrdenTrabajo::Entregado);
});

test('marcar entregada desde a domicilio solo funciona en ese estado', function () {
    $user = User::factory()->create();
    $orden = crearOrdenDesdePedido($user, pedidoConPago($user));

    $this->actingAs($user)->postJson("/api/v1/ordenes-trabajo/{$orden->id}/entregar")
        ->assertStatus(422);
});
