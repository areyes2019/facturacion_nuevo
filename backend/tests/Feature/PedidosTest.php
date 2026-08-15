<?php

use App\Enums\EstadoPedido;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoCuenta;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cuenta;
use App\Models\Emisor;
use App\Models\MovimientoInventario;
use App\Models\Pedido;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

function articuloParaPedido(User $user, array $overrides = []): Articulo
{
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    return Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Sello automático 40x15',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 100.00,
        'existencia' => 10,
    ], $overrides));
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

    expect($articulo->fresh()->existencia)->toBe(8);
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
    expect($articulo->fresh()->existencia)->toBe(7);
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

    expect($articulo->fresh()->existencia)->toBe(10);
    $this->assertDatabaseMissing('pedidos', ['id' => $pedido->id]);
});

test('escanear el qr cobra el saldo a la caja de efectivo y marca entregado', function () {
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

    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar");

    $response->assertOk();
    $response->assertJsonPath('ya_estaba_entregado', false);
    $response->assertJsonPath('cobrado', 132);
    $response->assertJsonPath('cuenta_nombre', 'Caja General');
    $response->assertJsonPath('pedido.estado', 'entregado');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    $this->assertDatabaseHas('pedido_pagos', ['pedido_id' => $pedido->id, 'automatico' => true, 'monto' => 132.00]);
});

test('escanear dos veces no cobra dos veces', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    $cuenta = cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();
    $segundo = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar");

    $segundo->assertJsonPath('ya_estaba_entregado', true);
    $segundo->assertJsonPath('cobrado', 0);

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);
    expect(Pedido::find($pedido->id)->pagos()->count())->toBe(1);
});

test('sin cuenta de efectivo la entrega se registra pero avisa que falta cobrar', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar");

    $response->assertOk();
    $response->assertJsonPath('cobrado', 0);
    expect($response->json('aviso'))->toContain('cuenta de efectivo');
    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Entregado);
});

test('deshacer la entrega borra el pago automatico y devuelve el saldo de la cuenta', function () {
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

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();
    $response = $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/deshacer-entrega");

    $response->assertOk();
    $response->assertJsonPath('pedido.estado', 'anticipo');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(100.0);
    $this->assertDatabaseMissing('pedido_pagos', ['pedido_id' => $pedido->id, 'automatico' => true]);
});

test('pasada la ventana ya no se puede deshacer la entrega', function () {
    $user = User::factory()->create();
    $articulo = articuloParaPedido($user);
    cajaDe($user);

    $this->actingAs($user)->postJson('/api/v1/pedidos', datosPedidoValidos($articulo))->assertCreated();
    $pedido = Pedido::where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/entregar")->assertOk();

    Pedido::where('id', $pedido->id)->update([
        'entregado_en' => now()->subMinutes(Pedido::MINUTOS_PARA_DESHACER_ENTREGA + 1),
    ]);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/deshacer-entrega")->assertUnprocessable();
    expect(Pedido::find($pedido->id)->estado)->toBe(EstadoPedido::Entregado);
});

test('el ticket se genera como jpeg y se invalida al registrar un pago', function () {
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

    $ruta = Pedido::find($pedido->id)->ticket_ruta;
    expect($ruta)->not->toBeNull();

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedido->id}/pagos", [
        'fecha_pago' => now()->toDateString(),
        'monto' => 50.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    // El ticket muestra el saldo pendiente: si sobreviviera a un pago, mentiría.
    expect(Pedido::find($pedido->id)->ticket_ruta)->toBeNull();
    Storage::disk('local')->assertMissing($ruta);
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
