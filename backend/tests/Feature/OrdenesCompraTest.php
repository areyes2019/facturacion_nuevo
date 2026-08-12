<?php

use App\Enums\EstadoOrdenCompra;
use App\Mail\OrdenCompraEnviadaMail;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\OrdenCompra;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\TwilioWhatsAppService;
use Illuminate\Support\Facades\Mail;

/**
 * Crea un proveedor con un catálogo y un artículo suyo. El costo del artículo (lo que le pagas al
 * proveedor) es lo que precarga la línea de la orden, no su precio de venta (ver 012).
 */
function crearProveedorYArticuloParaOrden(User $user, array $overridesArticulo = []): array
{
    $proveedor = Proveedor::factory()->for($user)->create([
        'nombre_comercial' => 'Distribuidora Ejemplo SA de CV',
        'correo' => 'compras@proveedor.test',
        'telefono' => '+525512345678',
    ]);

    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create(['descuento' => 0]);

    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Teclado mecánico',
        'modelo' => 'MOD-1234',
        'precio_proveedor' => 100.00,
        'costo_con_descuento' => 100.00,
    ], $overridesArticulo));

    return [$proveedor, $articulo];
}

function datosOrdenValidos(Proveedor $proveedor, Articulo $articulo, array $overrides = []): array
{
    return array_merge([
        'proveedor_id' => $proveedor->id,
        'lineas' => [
            [
                'articulo_id' => $articulo->id,
                'cantidad' => 2,
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'precio_unitario' => 100.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 232.00,
    ], $overrides);
}

/**
 * Deja una orden lista para pagar (estado `enviada`) con una cuenta con saldo suficiente.
 */
function ordenEnviadaConCuenta(User $user, float $saldoInicial = 1000.0): array
{
    [$proveedor, $articulo] = crearProveedorYArticuloParaOrden($user);

    $orden = OrdenCompra::factory()->for($user)->for($proveedor)->create([
        'estado' => EstadoOrdenCompra::Enviada->value,
    ]);
    $orden->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 2,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 100.00,
        'tasa_iva' => '16',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);

    $cuenta = Cuenta::factory()->for($user)->create([
        'saldo_inicial' => $saldoInicial,
        'saldo_actual' => $saldoInicial,
    ]);

    return [$orden, $cuenta];
}

test('un invitado no puede acceder a ordenes de compra', function () {
    $this->getJson('/api/v1/ordenes-compra')->assertUnauthorized();
});

// ---------------------------------------------------------------------------------------------
// Captura
// ---------------------------------------------------------------------------------------------

test('crear una orden la deja en borrador con folio y totales calculados', function () {
    $user = User::factory()->create();
    [$proveedor, $articulo] = crearProveedorYArticuloParaOrden($user);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-compra', datosOrdenValidos($proveedor, $articulo));

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'borrador');
    $response->assertJsonPath('data.folio', 1);
    $response->assertJsonPath('data.folio_formateado', 'OC-00001');
    $response->assertJsonPath('data.subtotal', 200);
    $response->assertJsonPath('data.total_iva_16', 32);
    $response->assertJsonPath('data.total', 232);
    $response->assertJsonPath('data.esta_pagada', false);
    $this->assertDatabaseHas('ordenes_compra', ['user_id' => $user->id, 'estado' => 'borrador', 'folio' => 1]);
});

test('si el total no coincide con lo calculado la peticion se rechaza', function () {
    $user = User::factory()->create();
    [$proveedor, $articulo] = crearProveedorYArticuloParaOrden($user);

    $response = $this->actingAs($user)->postJson(
        '/api/v1/ordenes-compra',
        datosOrdenValidos($proveedor, $articulo, ['total' => 999])
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('total');
});

test('no se puede ordenar a un proveedor ajeno', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [, $articulo] = crearProveedorYArticuloParaOrden($user);
    [$proveedorAjeno] = crearProveedorYArticuloParaOrden($otro);

    $response = $this->actingAs($user)->postJson(
        '/api/v1/ordenes-compra',
        datosOrdenValidos($proveedorAjeno, $articulo)
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('proveedor_id');
});

test('un articulo de otro proveedor no puede ir en la orden', function () {
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);
    [, $articuloDeOtroProveedor] = crearProveedorYArticuloParaOrden($user);

    $response = $this->actingAs($user)->postJson(
        '/api/v1/ordenes-compra',
        datosOrdenValidos($proveedor, $articuloDeOtroProveedor)
    );

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('lineas.0.articulo_id');
});

test('la orden guarda fecha esperada de entrega y observaciones', function () {
    $user = User::factory()->create();
    [$proveedor, $articulo] = crearProveedorYArticuloParaOrden($user);

    $response = $this->actingAs($user)->postJson('/api/v1/ordenes-compra', datosOrdenValidos($proveedor, $articulo, [
        'fecha_entrega_esperada' => '2026-09-15',
        'observaciones' => 'Entregar en el almacén de la calle 5.',
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.fecha_entrega_esperada', '2026-09-15');
    $response->assertJsonPath('data.observaciones', 'Entregar en el almacén de la calle 5.');
});

// ---------------------------------------------------------------------------------------------
// Envío al proveedor
// ---------------------------------------------------------------------------------------------

test('enviar por correo cambia el estado de borrador a enviada', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);
    $orden = OrdenCompra::factory()->for($user)->for($proveedor)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/enviar", [
        'canal' => 'correo',
        'destinatarios' => ['compras@proveedor.test'],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('ordenes_compra', ['id' => $orden->id, 'estado' => 'enviada']);
    Mail::assertSent(OrdenCompraEnviadaMail::class);
});

test('enviar por whatsapp usa el servicio de twilio y cambia el estado', function () {
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);
    $orden = OrdenCompra::factory()->for($user)->for($proveedor)->create();

    // Sin llamada de red real: se verifica el contrato (teléfono, resumen y URL pública firmada).
    $this->mock(TwilioWhatsAppService::class, function ($mock) {
        $mock->shouldReceive('enviar')
            ->once()
            ->withArgs(fn (string $telefono, string $mensaje, string $urlPdf) => $telefono === '+525512345678'
                && str_contains($mensaje, 'Orden de compra OC-')
                && str_contains($urlPdf, 'pdf-publico'));
    });

    $this->actingAs($user)
        ->postJson("/api/v1/ordenes-compra/{$orden->id}/enviar", [
            'canal' => 'whatsapp',
            'telefono' => '+525512345678',
        ])
        ->assertOk();

    $this->assertDatabaseHas('ordenes_compra', ['id' => $orden->id, 'estado' => 'enviada']);
});

// ---------------------------------------------------------------------------------------------
// Pago de contado y Tesorería
// ---------------------------------------------------------------------------------------------

test('pagar una orden genera un egreso en tesoreria y la deja pagada', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'pagada');
    $response->assertJsonPath('data.esta_pagada', true);
    $response->assertJsonPath('data.fecha_pago', '2026-08-05');

    $movimiento = Movimiento::where('documentable_type', OrdenCompra::class)
        ->where('documentable_id', $orden->id)
        ->first();

    expect($movimiento)->not->toBeNull();
    expect($movimiento->tipo->value)->toBe('egreso');
    expect((float) $movimiento->monto)->toBe(232.00);
    expect($movimiento->concepto)->toBe('Pago de Orden de compra '.$orden->folioFormateado());
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(768.00);
});

test('el monto del pago siempre es el total de la orden e ignora lo que mande el cliente', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
        'monto' => 1.00,
    ])->assertOk();

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(768.00);
});

test('un pago que dejaria la cuenta en negativo se rechaza y la orden sigue enviada', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user, saldoInicial: 100.0);

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ]);

    $response->assertUnprocessable();
    $this->assertDatabaseHas('ordenes_compra', ['id' => $orden->id, 'estado' => 'enviada', 'cuenta_id' => null]);
    $this->assertDatabaseCount('movimientos', 0);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(100.00);
});

test('no se puede pagar con una cuenta inactiva', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);
    $cuenta->update(['activa' => false]);

    $this->actingAs($user)
        ->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
            'cuenta_id' => $cuenta->id,
            'fecha_pago' => '2026-08-05',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_id');
});

test('solo se puede pagar una orden enviada', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);
    $orden->update(['estado' => EstadoOrdenCompra::Borrador->value]);

    $this->actingAs($user)
        ->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
            'cuenta_id' => $cuenta->id,
            'fecha_pago' => '2026-08-05',
        ])
        ->assertUnprocessable();
});

test('cancelar el pago revierte el egreso el saldo y el estado', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();

    $response = $this->actingAs($user)->deleteJson("/api/v1/ordenes-compra/{$orden->id}/pago");

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'enviada');
    $response->assertJsonPath('data.esta_pagada', false);
    $this->assertDatabaseCount('movimientos', 0);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(1000.00);
});

test('no se puede cancelar el pago de una orden ya recibida', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();
    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/v1/ordenes-compra/{$orden->id}/pago")
        ->assertUnprocessable();
});

test('el movimiento de la orden aparece como automatico y no se puede editar desde tesoreria', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();

    $movimiento = Movimiento::first();

    $this->actingAs($user)
        ->getJson('/api/v1/movimientos')
        ->assertOk()
        ->assertJsonPath('data.0.es_automatico', true)
        ->assertJsonPath('data.0.tipo', 'egreso')
        ->assertJsonPath('data.0.documento_origen.tipo', 'orden_compra')
        ->assertJsonPath('data.0.documento_origen.ruta', 'ordenes-compra-detalle')
        ->assertJsonPath('data.0.documento_origen.etiqueta', $orden->folioFormateado());

    $this->actingAs($user)
        ->deleteJson("/api/v1/movimientos/{$movimiento->id}")
        ->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------
// Recepción, edición y borrado
// ---------------------------------------------------------------------------------------------

test('una orden pagada puede marcarse como recibida', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();

    $this->actingAs($user)
        ->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")
        ->assertOk()
        ->assertJsonPath('data.estado', 'recibida');
});

test('no se puede recibir una orden que no esta pagada', function () {
    $user = User::factory()->create();
    [$orden] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)
        ->postJson("/api/v1/ordenes-compra/{$orden->id}/recibir")
        ->assertUnprocessable();
});

test('editar una orden enviada la regresa a borrador', function () {
    $user = User::factory()->create();
    [$proveedor, $articulo] = crearProveedorYArticuloParaOrden($user);
    $orden = OrdenCompra::factory()->for($user)->for($proveedor)->create([
        'estado' => EstadoOrdenCompra::Enviada->value,
    ]);

    $this->actingAs($user)
        ->putJson("/api/v1/ordenes-compra/{$orden->id}", datosOrdenValidos($proveedor, $articulo))
        ->assertOk()
        ->assertJsonPath('data.estado', 'borrador');
});

test('una orden pagada no se puede editar', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);
    $proveedor = $orden->proveedor;
    $articulo = Articulo::first();

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();

    $this->actingAs($user)
        ->putJson("/api/v1/ordenes-compra/{$orden->id}", datosOrdenValidos($proveedor, $articulo))
        ->assertUnprocessable();
});

test('solo se puede eliminar una orden en borrador', function () {
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);

    $borrador = OrdenCompra::factory()->for($user)->for($proveedor)->create();
    $enviada = OrdenCompra::factory()->for($user)->for($proveedor)->create([
        'estado' => EstadoOrdenCompra::Enviada->value,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/ordenes-compra/{$borrador->id}")->assertNoContent();
    $this->actingAs($user)->deleteJson("/api/v1/ordenes-compra/{$enviada->id}")->assertUnprocessable();
});

// ---------------------------------------------------------------------------------------------
// Duplicar, listado y aislamiento entre usuarios
// ---------------------------------------------------------------------------------------------

test('duplicar crea una copia en borrador con folio propio y sin pago', function () {
    $user = User::factory()->create();
    [$orden, $cuenta] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/pagar", [
        'cuenta_id' => $cuenta->id,
        'fecha_pago' => '2026-08-05',
    ])->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/ordenes-compra/{$orden->id}/duplicar");

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'borrador');
    $response->assertJsonPath('data.esta_pagada', false);
    $response->assertJsonPath('data.proveedor_id', $orden->proveedor_id);
    $response->assertJsonCount(1, 'data.lineas');
    expect($response->json('data.folio'))->toBeGreaterThan($orden->folio);
});

test('el listado filtra por proveedor folio y estado de forma combinable', function () {
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);
    $otroProveedor = Proveedor::factory()->for($user)->create(['nombre_comercial' => 'Refacciones del Norte']);

    OrdenCompra::factory()->for($user)->for($proveedor)->create([
        'folio' => 1,
        'estado' => EstadoOrdenCompra::Enviada->value,
    ]);
    OrdenCompra::factory()->for($user)->for($otroProveedor)->create([
        'folio' => 2,
        'estado' => EstadoOrdenCompra::Borrador->value,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/ordenes-compra?proveedor=Distribuidora&estado=enviada')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.folio', 1);

    $this->actingAs($user)
        ->getJson('/api/v1/ordenes-compra?folio=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.folio', 2);
});

test('un usuario no ve ni consulta las ordenes de otro', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [$proveedorAjeno] = crearProveedorYArticuloParaOrden($otro);
    $ordenAjena = OrdenCompra::factory()->for($otro)->for($proveedorAjeno)->create();

    $this->actingAs($user)->getJson('/api/v1/ordenes-compra')->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($user)->getJson("/api/v1/ordenes-compra/{$ordenAjena->id}")->assertNotFound();
});

test('el pdf de la orden se genera al vuelo', function () {
    $user = User::factory()->create();
    [$orden] = ordenEnviadaConCuenta($user);

    $this->actingAs($user)
        ->get("/api/v1/ordenes-compra/{$orden->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

// Una orden `recibida` deja de bloquear el borrado de su proveedor, así que el proveedor puede
// quedar eliminado mientras la orden sigue existiendo como documento histórico.
test('el detalle y el pdf siguen funcionando si el proveedor fue eliminado', function () {
    $user = User::factory()->create();
    [$orden] = ordenEnviadaConCuenta($user);
    $orden->update(['estado' => EstadoOrdenCompra::Recibida->value]);

    $this->actingAs($user)
        ->deleteJson("/api/v1/proveedores/{$orden->proveedor_id}")
        ->assertNoContent();

    $this->actingAs($user)
        ->getJson("/api/v1/ordenes-compra/{$orden->id}")
        ->assertOk()
        ->assertJsonPath('data.proveedor_nombre_comercial', 'Distribuidora Ejemplo SA de CV');

    $this->actingAs($user)
        ->get("/api/v1/ordenes-compra/{$orden->id}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('el pdf publico requiere una firma valida', function () {
    $user = User::factory()->create();
    [$orden] = ordenEnviadaConCuenta($user);

    $this->get("/api/v1/ordenes-compra/{$orden->id}/pdf-publico")->assertForbidden();
    $this->get($orden->urlPdfPublico())->assertOk();
});

// ---------------------------------------------------------------------------------------------
// Filtro de artículos por proveedor (alimenta el selector del formulario)
// ---------------------------------------------------------------------------------------------

test('el listado de articulos se puede filtrar por proveedor', function () {
    $user = User::factory()->create();
    [$proveedor] = crearProveedorYArticuloParaOrden($user);
    crearProveedorYArticuloParaOrden($user);

    $this->actingAs($user)
        ->getJson("/api/v1/articulos?proveedor_id={$proveedor->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.proveedor_id', $proveedor->id);

    $this->actingAs($user)->getJson('/api/v1/articulos')->assertOk()->assertJsonCount(2, 'data');
});
