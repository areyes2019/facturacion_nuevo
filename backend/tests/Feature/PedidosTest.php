<?php

use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoCuenta;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cuenta;
use App\Models\Emisor;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * `$existencia` es aparte de `$overrides` (que va a `Articulo::factory()`) porque desde la
 * revisión del 2026-08-26 de 017-inventario.md existencia vive en su propia tabla; `null` no crea
 * fila (el artículo queda sin marcar "en existencias").
 */
function articuloParaPedido(User $user, array $overrides = [], ?int $existencia = 10): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Sello automático 40x15',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 100.00,
    ], $overrides));

    if ($existencia !== null) {
        Existencia::factory()->create(['articulo_id' => $articulo->id, 'existencia' => $existencia]);
    }

    return $articulo;
}

function datosPedidoValidos(Articulo $articulo, array $overrides = []): array
{
    return array_merge([
        'cliente_nombre' => 'María Pérez',
        'cliente_telefono' => '5512345678',
        'cliente_correo' => 'maria@ejemplo.com',
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

function cajaDe(User $user): Cuenta
{
    return Cuenta::factory()->for($user)->create([
        'nombre' => 'Caja General',
        'tipo' => TipoCuenta::Efectivo->value,
        'saldo_inicial' => 0,
        'saldo_actual' => 0,
        'activa' => true,
    ]);
}

/**
 * Un pedido ya cobrado por completo, listo para las pruebas de entrega y autofactura.
 *
 * @return array{0: Pedido, 1: Cuenta}
 */
function pedidoPagado(User $user, float $monto = 232.00): array
{
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    test()->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->latest('id')->firstOrFail();

    test()->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => $monto,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    return [$pedido->fresh(), $cuenta];
}

test('un invitado no puede acceder a pedidos', function () {
    $this->getJson('/api/v1/pedidos')->assertUnauthorized();
});

test('crear un pedido calcula los totales y lo deja pendiente con folio propio', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $response = $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo));

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'pendiente');
    $response->assertJsonPath('data.folio', 1);
    $response->assertJsonPath('data.numero_ticket', '00001');
    $response->assertJsonPath('data.subtotal', 200);
    $response->assertJsonPath('data.total_iva_16', 32);
    $response->assertJsonPath('data.total', 232);
    $response->assertJsonPath('data.saldo_pendiente', 232);
});

test('el folio del pedido es propio y no comparte numeracion con facturas ni cotizaciones', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $segundo = $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo));

    $segundo->assertJsonPath('data.folio', 2);
});

test('un pedido admite lineas libres sin articulo del catalogo', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $response = $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo, [
        'lineas' => [
            [
                'articulo_id' => null,
                'cantidad' => 1,
                'descripcion' => 'Grabado especial a mano',
                'precio_unitario' => 150.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 174.00,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.lineas.0.es_libre', true);
    $response->assertJsonPath('data.total', 174);
});

test('si el total no coincide con lo calculado la peticion se rechaza', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $response = $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo, ['total' => 999]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('total');
});

test('crear el pedido descuenta existencias solo de las lineas con articulo', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo, [
        'lineas' => [
            [
                'articulo_id' => $articulo->id,
                'cantidad' => 2,
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'precio_unitario' => 100.00,
                'tasa_iva' => '16',
            ],
            [
                'articulo_id' => null,
                'cantidad' => 5,
                'descripcion' => 'Servicio suelto',
                'precio_unitario' => 100.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 812.00,
    ]))->assertCreated();

    expect(Existencia::where('articulo_id', $articulo->id)->first()->existencia)->toBe(8);
    expect(MovimientoInventario::where('motivo', MotivoMovimientoInventario::VentaPedido->value)->count())->toBe(1);
});

test('un pago parcial deja el pedido en anticipo y genera su movimiento de tesoreria', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'anticipo');
    $response->assertJsonPath('data.saldo_pendiente', 132);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(100.0);
});

test('cubrir el total deja el pedido pagado y genera el enlace de autofactura', function () {
    $user = User::factory()->create();
    [$pedido] = pedidoPagado($user);

    expect($pedido->estado)->toBe(EstadoPedido::Pagado);
    expect($pedido->autofactura_token)->not->toBeNull();
    expect(strlen((string) $pedido->autofactura_token))->toBe(64);
});

test('no se puede pagar de mas', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 500.00,
        'cuenta_id' => $cuenta->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('monto');
});

test('un pedido pagado ya no se puede editar', function () {
    $user = User::factory()->create();
    [$pedido] = pedidoPagado($user);
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)
        ->putJson("/api/v1/pedidos/{$pedido->id}", datosPedidoValidos($articulo))
        ->assertUnprocessable();
});

test('editar un pedido no descuenta el inventario dos veces', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->putJson("/api/v1/pedidos/{$pedido->id}", datosPedidoValidos($articulo, [
        'lineas' => [
            [
                'articulo_id' => $articulo->id,
                'cantidad' => 3,
                'descripcion' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'precio_unitario' => 100.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 348.00,
    ]))->assertOk();

    // 10 - 2 (alta) + 2 (corrección) - 3 (nueva salida) = 7.
    expect(Existencia::where('articulo_id', $articulo->id)->first()->existencia)->toBe(7);
});

test('no se puede eliminar un pedido con pagos', function () {
    $user = User::factory()->create();
    [$pedido] = pedidoPagado($user);

    $this->actingAs($user)->deleteJson("/api/v1/pedidos/{$pedido->id}")->assertUnprocessable();
});

test('eliminar un pedido sin pagos devuelve las existencias', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->deleteJson("/api/v1/pedidos/{$pedido->id}")->assertNoContent();

    expect(Existencia::where('articulo_id', $articulo->id)->first()->existencia)->toBe(10);
    $this->assertDatabaseMissing('pedidos', ['id' => $pedido->id]);
});

test('escanear con saldo pendiente cobra el saldo exacto a la cuenta elegida y marca entregado', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar", [
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('ya_estaba_entregado', false);
    $response->assertJsonPath('cobrado', 132);
    $response->assertJsonPath('cuenta_nombre', 'Caja General');
    $response->assertJsonPath('pedido.estado', 'entregado');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    $this->assertDatabaseHas('pedido_pagos', [
        'pedido_id' => $pedido->id,
        'registrado_al_entregar' => true,
        'monto' => 132.00,
    ]);
});

test('escanear con saldo pendiente y sin cuenta se rechaza sin tocar nada', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_id');

    // Ni entrega a medias ni dinero en la caja: la venta sigue exactamente como estaba.
    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Pendiente);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(0.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(0);
});

test('escanear un pedido ya pagado lo cierra sin registrar ningun pago', function () {
    $user = User::factory()->create();
    [$pedido, $cuenta] = pedidoPagado($user);

    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar");

    $response->assertOk();
    $response->assertJsonPath('cobrado', 0);
    $response->assertJsonPath('cuenta_nombre', null);
    $response->assertJsonPath('pedido.estado', 'entregado');

    // El saldo de la cuenta no se mueve: no entró un peso más que el que ya estaba.
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(1);
});

test('mandar cuenta en un pedido sin saldo se rechaza', function () {
    $user = User::factory()->create();
    [$pedido, $cuenta] = pedidoPagado($user);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar", [
        'cuenta_id' => $cuenta->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('cuenta_id');

    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Pagado);
});

test('escanear dos veces no cobra dos veces', function () {
    $user = User::factory()->create();
    [$pedido, $cuenta] = pedidoPagado($user);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();
    $segundo = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar");

    $segundo->assertJsonPath('ya_estaba_entregado', true);
    $segundo->assertJsonPath('cobrado', 0);

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(1);
});

test('deshacer una entrega que no cobro nada regresa el pedido a su estado', function () {
    $user = User::factory()->create();
    [$pedido, $cuenta] = pedidoPagado($user);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();
    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/deshacer-entrega");

    $response->assertOk();
    $response->assertJsonPath('pedido.estado', 'pagado');

    expect(Pedido::find($pedido->id)->entregado_en)->toBeNull();
    // El pago del cliente sigue donde estaba: deshacer nunca toca dinero.
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(1);
});

test('no se puede deshacer una entrega que registro un cobro', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar", [
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    // Ese cobro pasó por la confirmación del usuario: revertirlo a ciegas provocaría el descuido
    // que "Deshacer" pretende evitar. Se corrige borrando el pago desde el detalle.
    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/deshacer-entrega")
        ->assertUnprocessable();

    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Entregado);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(2);
});

test('pasada la ventana ya no se puede deshacer la entrega', function () {
    $user = User::factory()->create();
    [$pedido] = pedidoPagado($user);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();

    Pedido::where('id', $pedido->id)->update([
        'entregado_en' => now()->subMinutes(Pedido::MINUTOS_PARA_DESHACER_ENTREGA + 1),
    ]);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/deshacer-entrega")->assertUnprocessable();
    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Entregado);
});

test('el ticket es un jpeg con el qr y no deja ningun archivo en el disco', function () {
    Storage::fake('local');
    Emisor::create(['nombre' => 'Sellos del Norte', 'rfc' => 'AAA010101AAA', 'telefono' => '5599887766']);

    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)->get("/api/v1/pedidos/{$pedido->id}/ticket");

    $response->assertOk();
    $response->assertHeader('Content-Type', 'image/jpeg');
    expect(substr($response->getContent(), 0, 2))->toBe("\xFF\xD8");

    // El QR se pega dentro de la imagen, así que el ticket crece a lo alto muy por encima de lo que
    // ocuparían solo los renglones de texto de una venta de una línea.
    $medidas = getimagesizefromstring($response->getContent());
    expect($medidas[0])->toBe(576);
    expect($medidas[1])->toBeGreaterThan(600);

    // Úsese y tírese: se dibuja al pedirlo y no queda nada guardado.
    expect(Storage::disk('local')->allFiles())->toBe([]);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 50.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    // Y como nunca se guarda, es imposible que muestre un saldo viejo: la segunda petición lo
    // vuelve a dibujar con los datos del momento.
    $segundo = $this->actingAs($user)->get("/api/v1/pedidos/{$pedido->id}/ticket");
    $segundo->assertOk();
    expect($segundo->getContent())->not->toBe($response->getContent());
    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('el mensaje del ticket resuelve los huecos y deja intactos los que no existen', function () {
    $user = User::factory()->create();
    $user->configuraciones()->create([
        'clave' => 'mensaje_ticket',
        'valor' => 'Hola {nombre}, ticket {folio}: total {total}, saldo {saldo}. {inexistente}',
    ]);

    [$pedido] = pedidoPagado($user);

    $response = $this->actingAs($user)->getJson("/api/v1/pedidos/{$pedido->id}");

    $response->assertJsonPath(
        'data.mensaje_compartible',
        'Hola María Pérez, ticket 00001: total $232.00, saldo $0.00. {inexistente}',
    );
});

test('el aviso de pedido listo sale de configuracion con los huecos resueltos', function () {
    $user = User::factory()->create();
    $user->configuraciones()->create([
        'clave' => 'mensaje_listo',
        'valor' => 'Hola {nombre}, tu pedido {folio} está listo. Quedan {saldo}.',
    ]);

    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->getJson("/api/v1/pedidos/{$pedido->id}")->assertJsonPath(
        'data.mensaje_listo',
        'Hola María Pérez, tu pedido 00001 está listo. Quedan $132.00.',
    );
});

test('el aviso de pedido listo trae el texto de fabrica sin configurar nada', function () {
    $user = User::factory()->create();
    [$pedido] = pedidoPagado($user);

    $response = $this->actingAs($user)->getJson("/api/v1/pedidos/{$pedido->id}");

    expect($response->json('data.mensaje_listo'))
        ->toContain('María Pérez')
        ->toContain('00001')
        ->toContain('ya está listo');
});

test('la sugerencia por telefono trae los datos del ultimo pedido de ese numero', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();

    $response = $this->actingAs($user)->getJson('/api/v1/pedidos/por-telefono?telefono=5512345678');

    $response->assertJsonPath('encontrado', true);
    $response->assertJsonPath('cliente_nombre', 'María Pérez');
    $response->assertJsonPath('cliente_correo', 'maria@ejemplo.com');
});

test('no se puede ver el pedido de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [$pedido] = pedidoPagado($otro);

    $this->actingAs($user)->getJson("/api/v1/pedidos/{$pedido->id}")->assertNotFound();
});
