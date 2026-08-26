<?php

use App\Enums\EstadoCotizacion;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionPago;
use App\Models\Cuenta;
use App\Models\Existencia;
use App\Models\Movimiento;
use App\Models\Proveedor;
use App\Models\User;

function datosCuentaValidos(array $overrides = []): array
{
    return array_merge([
        'nombre' => 'Caja General',
        'tipo' => 'efectivo',
        'saldo_inicial' => 1000,
    ], $overrides);
}

function datosMovimientoValidos(Cuenta $cuenta, array $overrides = []): array
{
    return array_merge([
        'tipo' => 'ingreso',
        'cuenta_id' => $cuenta->id,
        'monto' => 500,
        'fecha' => '2026-08-04',
        'concepto' => 'Venta de mostrador',
    ], $overrides);
}

// ---------------------------------------------------------------------------------------------
// Cuentas (UC-01, UC-07)
// ---------------------------------------------------------------------------------------------

test('un invitado no puede acceder a tesoreria', function () {
    $this->getJson('/api/v1/cuentas')->assertUnauthorized();
    $this->getJson('/api/v1/movimientos')->assertUnauthorized();
    $this->postJson('/api/v1/transferencias')->assertUnauthorized();
});

test('un usuario autenticado puede crear una cuenta y su saldo actual arranca igual al inicial', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/v1/cuentas', datosCuentaValidos());

    $response->assertCreated();
    $response->assertJsonPath('data.nombre', 'Caja General');
    $response->assertJsonPath('data.tipo', 'efectivo');
    $response->assertJsonPath('data.saldo_inicial', 1000);
    $response->assertJsonPath('data.saldo_actual', 1000);
    $response->assertJsonPath('data.activa', true);
});

test('el tipo de cuenta debe ser uno de los cuatro valores permitidos', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/cuentas', datosCuentaValidos(['tipo' => 'criptomoneda']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tipo');
});

test('el saldo inicial de una cuenta no puede ser negativo', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/cuentas', datosCuentaValidos(['saldo_inicial' => -50]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('saldo_inicial');
});

test('el saldo inicial enviado en la edicion se ignora', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $response = $this->actingAs($user)->putJson("/api/v1/cuentas/{$cuenta->id}", [
        'nombre' => 'Caja Chica',
        'tipo' => 'efectivo',
        'saldo_inicial' => 999999,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.nombre', 'Caja Chica');
    $response->assertJsonPath('data.saldo_inicial', 1000);
});

test('una cuenta se puede desactivar desde la edicion', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->putJson("/api/v1/cuentas/{$cuenta->id}", [
        'nombre' => $cuenta->nombre,
        'tipo' => 'banco',
        'activa' => false,
    ])->assertOk()->assertJsonPath('data.activa', false);
});

test('una cuenta inactiva no admite movimientos nuevos pero conserva su saldo consultable', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(300)->inactiva()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_id');

    $this->actingAs($user)->getJson('/api/v1/cuentas/saldos')
        ->assertOk()
        ->assertJsonPath('data.0.saldo_actual', 300)
        ->assertJsonPath('total_global', 300);
});

test('una cuenta sin movimientos se elimina fisicamente', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->deleteJson("/api/v1/cuentas/{$cuenta->id}")->assertNoContent();

    $this->assertDatabaseMissing('cuentas', ['id' => $cuenta->id]);
});

test('una cuenta con movimientos no se puede eliminar y responde 409', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta))->assertCreated();

    $response = $this->actingAs($user)->deleteJson("/api/v1/cuentas/{$cuenta->id}");

    $response->assertStatus(409);
    $response->assertJsonPath('message', 'No se puede eliminar: la cuenta tiene movimientos registrados');
    $this->assertDatabaseHas('cuentas', ['id' => $cuenta->id]);
});

test('un usuario no puede ver ni editar la cuenta de otro usuario', function () {
    $user = User::factory()->create();
    $cuentaAjena = Cuenta::factory()->for(User::factory()->create())->create();

    $this->actingAs($user)->getJson("/api/v1/cuentas/{$cuentaAjena->id}")->assertNotFound();
    $this->actingAs($user)->deleteJson("/api/v1/cuentas/{$cuentaAjena->id}")->assertNotFound();
});

test('la consulta de saldos incluye cuentas activas e inactivas y el total global', function () {
    $user = User::factory()->create();
    Cuenta::factory()->for($user)->conSaldo(100)->create(['nombre' => 'A']);
    Cuenta::factory()->for($user)->conSaldo(250)->inactiva()->create(['nombre' => 'B']);

    $response = $this->actingAs($user)->getJson('/api/v1/cuentas/saldos');

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonPath('total_global', 350);
});

// ---------------------------------------------------------------------------------------------
// Movimientos manuales (UC-02, UC-03, UC-05)
// ---------------------------------------------------------------------------------------------

test('un ingreso manual aumenta el saldo de la cuenta de inmediato', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ingreso', 'monto' => 500]))
        ->assertCreated()
        ->assertJsonPath('data.es_automatico', false);

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(1500.0);
});

test('un egreso manual disminuye el saldo de la cuenta de inmediato', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'egreso', 'monto' => 400]))
        ->assertCreated();

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(600.0);
});

test('un ajuste corrige el saldo segun el signo del monto', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ajuste', 'monto' => -150]))
        ->assertCreated();

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(850.0);
});

test('un egreso que dejaria la cuenta en negativo se rechaza y no se persiste', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(100)->create();

    $response = $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'egreso', 'monto' => 500]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('monto');
    expect($response->json('errors.monto.0'))->toBe('El movimiento dejaría la cuenta con saldo negativo');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(100.0);
    $this->assertDatabaseCount('movimientos', 0);
});

test('un ajuste negativo que dejaria la cuenta en negativo se rechaza', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(100)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ajuste', 'monto' => -500]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto');

    $this->assertDatabaseCount('movimientos', 0);
});

test('el endpoint de movimientos no acepta el tipo transferencia', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'transferencia']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tipo');
});

test('un ingreso o egreso con monto cero o negativo se rechaza', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['monto' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto');

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['monto' => -10]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto');
});

test('un ajuste con monto cero se rechaza', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ajuste', 'monto' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('monto');
});

test('no se puede registrar un movimiento en la cuenta de otro usuario', function () {
    $user = User::factory()->create();
    $cuentaAjena = Cuenta::factory()->for(User::factory()->create())->conSaldo(1000)->create();

    $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuentaAjena))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('cuenta_id');
});

test('editar un movimiento manual recalcula el saldo de la cuenta', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $id = $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ingreso', 'monto' => 500]))
        ->json('data.id');

    $this->actingAs($user)->putJson("/api/v1/movimientos/{$id}", datosMovimientoValidos($cuenta, [
        'tipo' => 'ingreso',
        'monto' => 200,
        'concepto' => 'Venta corregida',
    ]))->assertOk()->assertJsonPath('data.concepto', 'Venta corregida');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(1200.0);
});

test('mover un movimiento a otra cuenta recalcula el saldo de ambas', function () {
    $user = User::factory()->create();
    $origen = Cuenta::factory()->for($user)->conSaldo(1000)->create();
    $destino = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $id = $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($origen, ['tipo' => 'ingreso', 'monto' => 500]))
        ->json('data.id');

    $this->actingAs($user)->putJson("/api/v1/movimientos/{$id}", datosMovimientoValidos($destino, [
        'tipo' => 'ingreso',
        'monto' => 500,
    ]))->assertOk();

    expect((float) $origen->fresh()->saldo_actual)->toBe(1000.0);
    expect((float) $destino->fresh()->saldo_actual)->toBe(500.0);
});

test('eliminar un movimiento manual recalcula el saldo de la cuenta', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $id = $this->actingAs($user)
        ->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'egreso', 'monto' => 300]))
        ->json('data.id');

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(700.0);

    $this->actingAs($user)->deleteJson("/api/v1/movimientos/{$id}")->assertNoContent();

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(1000.0);
    $this->assertDatabaseMissing('movimientos', ['id' => $id]);
});

test('el saldo actual siempre es el saldo inicial mas la suma de todos los movimientos', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();
    $otra = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $this->actingAs($user)->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ingreso', 'monto' => 500]))->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'egreso', 'monto' => 200]))->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/movimientos', datosMovimientoValidos($cuenta, ['tipo' => 'ajuste', 'monto' => -50]))->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $cuenta->id,
        'cuenta_destino_id' => $otra->id,
        'monto' => 250,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso a banco',
    ])->assertCreated();

    // 1000 + 500 - 200 - 50 - 250
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(1000.0);
    expect((float) $otra->fresh()->saldo_actual)->toBe(250.0);
});

// ---------------------------------------------------------------------------------------------
// Transferencias (UC-04)
// ---------------------------------------------------------------------------------------------

test('una transferencia mueve el saldo entre dos cuentas sin cambiar el total global', function () {
    $user = User::factory()->create();
    $origen = Cuenta::factory()->for($user)->conSaldo(1000)->create();
    $destino = Cuenta::factory()->for($user)->conSaldo(500)->create();

    $response = $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $origen->id,
        'cuenta_destino_id' => $destino->id,
        'monto' => 300,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso',
    ]);

    $response->assertCreated();
    $response->assertJsonCount(2, 'data');

    expect((float) $origen->fresh()->saldo_actual)->toBe(700.0);
    expect((float) $destino->fresh()->saldo_actual)->toBe(800.0);

    $this->actingAs($user)->getJson('/api/v1/cuentas/saldos')->assertJsonPath('total_global', 1500);

    // Las dos filas comparten transferencia_id y se compensan entre sí.
    $movimientos = Movimiento::where('tipo', 'transferencia')->get();
    expect($movimientos)->toHaveCount(2);
    expect($movimientos->pluck('transferencia_id')->unique())->toHaveCount(1);
    expect((float) $movimientos->sum('monto'))->toBe(0.0);
});

test('una transferencia entre una cuenta y ella misma se rechaza', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(1000)->create();

    $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $cuenta->id,
        'cuenta_destino_id' => $cuenta->id,
        'monto' => 100,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso',
    ])->assertUnprocessable()->assertJsonValidationErrors('cuenta_destino_id');

    $this->assertDatabaseCount('movimientos', 0);
});

test('una transferencia que dejaria la cuenta origen en negativo se rechaza completa', function () {
    $user = User::factory()->create();
    $origen = Cuenta::factory()->for($user)->conSaldo(100)->create();
    $destino = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $origen->id,
        'cuenta_destino_id' => $destino->id,
        'monto' => 500,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso',
    ])->assertUnprocessable()->assertJsonValidationErrors('monto');

    expect((float) $origen->fresh()->saldo_actual)->toBe(100.0);
    expect((float) $destino->fresh()->saldo_actual)->toBe(0.0);
    $this->assertDatabaseCount('movimientos', 0);
});

test('una transferencia hacia una cuenta inactiva se rechaza', function () {
    $user = User::factory()->create();
    $origen = Cuenta::factory()->for($user)->conSaldo(1000)->create();
    $destino = Cuenta::factory()->for($user)->inactiva()->create();

    $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $origen->id,
        'cuenta_destino_id' => $destino->id,
        'monto' => 100,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso',
    ])->assertUnprocessable()->assertJsonValidationErrors('cuenta_destino_id');
});

test('eliminar una de las dos filas de una transferencia elimina ambas', function () {
    $user = User::factory()->create();
    $origen = Cuenta::factory()->for($user)->conSaldo(1000)->create();
    $destino = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $movimientos = $this->actingAs($user)->postJson('/api/v1/transferencias', [
        'cuenta_origen_id' => $origen->id,
        'cuenta_destino_id' => $destino->id,
        'monto' => 300,
        'fecha' => '2026-08-04',
        'concepto' => 'Traspaso',
    ])->json('data');

    $this->actingAs($user)->deleteJson("/api/v1/movimientos/{$movimientos[0]['id']}")->assertNoContent();

    $this->assertDatabaseCount('movimientos', 0);
    expect((float) $origen->fresh()->saldo_actual)->toBe(1000.0);
    expect((float) $destino->fresh()->saldo_actual)->toBe(0.0);
});

// ---------------------------------------------------------------------------------------------
// Consulta de movimientos (UC-06)
// ---------------------------------------------------------------------------------------------

test('el listado de movimientos filtra de forma combinable por fecha cuenta tipo y concepto', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->conSaldo(10000)->create();
    $otra = Cuenta::factory()->for($user)->conSaldo(10000)->create();

    Movimiento::factory()->for($user)->for($cuenta)->create(['tipo' => 'ingreso', 'fecha' => '2026-08-01', 'concepto' => 'Venta de refacciones']);
    Movimiento::factory()->for($user)->for($cuenta)->create(['tipo' => 'egreso', 'fecha' => '2026-08-02', 'concepto' => 'Pago de renta']);
    Movimiento::factory()->for($user)->for($cuenta)->create(['tipo' => 'ingreso', 'fecha' => '2026-08-20', 'concepto' => 'Venta de llantas']);
    Movimiento::factory()->for($user)->for($otra)->create(['tipo' => 'ingreso', 'fecha' => '2026-08-01', 'concepto' => 'Venta en banco']);

    $this->actingAs($user)->getJson('/api/v1/movimientos')->assertJsonCount(4, 'data');
    $this->actingAs($user)->getJson('/api/v1/movimientos?tipo=ingreso')->assertJsonCount(3, 'data');
    $this->actingAs($user)->getJson("/api/v1/movimientos?cuenta_id={$cuenta->id}")->assertJsonCount(3, 'data');
    $this->actingAs($user)->getJson('/api/v1/movimientos?concepto=Venta')->assertJsonCount(3, 'data');
    $this->actingAs($user)->getJson('/api/v1/movimientos?fecha_desde=2026-08-01&fecha_hasta=2026-08-05')->assertJsonCount(3, 'data');

    // Los cuatro filtros combinados entre sí.
    $this->actingAs($user)
        ->getJson("/api/v1/movimientos?fecha_desde=2026-08-01&fecha_hasta=2026-08-05&cuenta_id={$cuenta->id}&tipo=ingreso&concepto=refacciones")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.concepto', 'Venta de refacciones');
});

test('el listado de movimientos viene ordenado por fecha descendente', function () {
    $user = User::factory()->create();
    $cuenta = Cuenta::factory()->for($user)->create();

    Movimiento::factory()->for($user)->for($cuenta)->create(['fecha' => '2026-08-01', 'concepto' => 'Vieja']);
    Movimiento::factory()->for($user)->for($cuenta)->create(['fecha' => '2026-08-20', 'concepto' => 'Nueva']);

    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.concepto', 'Nueva')
        ->assertJsonPath('data.1.concepto', 'Vieja');
});

test('un usuario no ve los movimientos de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    Movimiento::factory()->for($otro)->for(Cuenta::factory()->for($otro))->create();

    $this->actingAs($user)->getJson('/api/v1/movimientos')->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------------------------
// Integración con Cotizaciones (008)
// ---------------------------------------------------------------------------------------------

function cotizacionEnviadaConCuenta(User $user, float $total = 232.00): array
{
    $cliente = Cliente::factory()->for($user)->create();
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::Enviada->value,
        'folio' => 15,
        'total' => $total,
    ]);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    return [$cotizacion, $cuenta];
}

test('registrar un pago de cotizacion crea un movimiento de ingreso automatico en la cuenta elegida', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => '2026-08-04',
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $movimiento = Movimiento::first();

    expect($movimiento->tipo->value)->toBe('ingreso');
    expect((float) $movimiento->monto)->toBe(100.0);
    expect($movimiento->fecha->toDateString())->toBe('2026-08-04');
    expect($movimiento->cuenta_id)->toBe($cuenta->id);
    expect($movimiento->concepto)->toBe('Anticipo de Cotización COT-00015');
    expect($movimiento->es_automatico)->toBeTrue();
    expect($movimiento->documentable)->toBeInstanceOf(CotizacionPago::class);

    expect((float) $cuenta->fresh()->saldo_actual)->toBe(100.0);
});

test('el concepto del movimiento automatico refleja el tipo de pago', function (string $tipo, string $concepto) {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => $tipo,
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    expect(Movimiento::first()->concepto)->toBe($concepto);
})->with([
    ['pago_total', 'Pago total de Cotización COT-00015'],
    ['saldo', 'Saldo de Cotización COT-00015'],
]);

test('no se puede registrar un pago de cotizacion en una cuenta inactiva', function () {
    $user = User::factory()->create();
    [$cotizacion] = cotizacionEnviadaConCuenta($user);
    $inactiva = Cuenta::factory()->for($user)->inactiva()->create();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $inactiva->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('cuenta_id');

    $this->assertDatabaseCount('movimientos', 0);
});

test('crear una cotizacion no genera ningun movimiento financiero', function () {
    $user = User::factory()->create();
    [$cotizacion] = cotizacionEnviadaConCuenta($user);

    expect($cotizacion->exists)->toBeTrue();
    $this->assertDatabaseCount('movimientos', 0);
});

test('un movimiento automatico no se puede editar ni eliminar desde tesoreria', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => '2026-08-04',
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $movimiento = Movimiento::first();

    $this->actingAs($user)
        ->putJson("/api/v1/movimientos/{$movimiento->id}", datosMovimientoValidos($cuenta))
        ->assertStatus(422);

    $this->actingAs($user)->deleteJson("/api/v1/movimientos/{$movimiento->id}")->assertStatus(422);

    $this->assertDatabaseHas('movimientos', ['id' => $movimiento->id]);
});

test('el listado de movimientos expone el documento origen de los automaticos', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.es_automatico', true)
        ->assertJsonPath('data.0.documento_origen.etiqueta', 'COT-00015')
        ->assertJsonPath('data.0.documento_origen.id', $cotizacion->id);
});

test('eliminar el pago mas reciente revierte su movimiento y el saldo de la cuenta', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $pagoId = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->json('data.pagos.0.id');

    expect($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Pagada);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(232.0);

    $this->actingAs($user)
        ->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos/{$pagoId}")
        ->assertNoContent();

    // Regresa a `enviada` porque la suma acumulada ya no alcanza el total.
    expect($cotizacion->fresh()->estado)->toBe(EstadoCotizacion::Enviada);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(0.0);
    $this->assertDatabaseCount('movimientos', 0);
    $this->assertDatabaseMissing('cotizacion_pagos', ['id' => $pagoId]);
});

test('eliminar un pago que no es el mas reciente se rechaza', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $anticipoId = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => '2026-08-04',
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->json('data.pagos.0.id');

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'saldo',
        'fecha_pago' => '2026-08-05',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos/{$anticipoId}")
        ->assertStatus(422);

    $this->assertDatabaseHas('cotizacion_pagos', ['id' => $anticipoId]);
    $this->assertDatabaseCount('movimientos', 2);
});

test('no se pueden eliminar pagos de una cotizacion con producto entregado', function () {
    $user = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($user);

    $pagoId = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->json('data.pagos.0.id');

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/entregar")->assertOk();

    $this->actingAs($user)
        ->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos/{$pagoId}")
        ->assertStatus(422);

    $this->assertDatabaseHas('cotizacion_pagos', ['id' => $pagoId]);
});

test('un usuario no puede eliminar el pago de la cotizacion de otro usuario', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [$cotizacion, $cuenta] = cotizacionEnviadaConCuenta($otro);

    $pagoId = $this->actingAs($otro)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->json('data.pagos.0.id');

    $this->actingAs($user)
        ->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos/{$pagoId}")
        ->assertNotFound();
});

// ---------------------------------------------------------------------------------------------
// Utilidad de venta en movimientos automáticos (010, "Utilidad de venta en movimientos
// automáticos")
// ---------------------------------------------------------------------------------------------

function clienteYArticuloConCosto(User $user, float $costo, float $precioVenta): array
{
    $cliente = Cliente::factory()->for($user)->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'costo_con_descuento' => $costo,
        'precio_unitario_sin_iva' => $precioVenta,
    ]);

    Existencia::factory()->create(['articulo_id' => $articulo->id, 'existencia' => 10]);

    return [$cliente, $articulo];
}

test('el movimiento de un pago de cotizacion expone la utilidad de venta del documento', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = clienteYArticuloConCosto($user, 60.00, 100.00);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $cotizacionId = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('cotizacion_lineas', [
        'cotizacion_id' => $cotizacionId,
        'costo_unitario' => 60.00,
    ]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacionId}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    // Importe neto sin IVA (2 × $100.00) menos costo (2 × $60.00) = $80.00 de utilidad.
    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.documento_origen.tipo', 'cotizacion')
        ->assertJsonPath('data.0.documento_origen.utilidad', 80)
        ->assertJsonPath('data.0.documento_origen.utilidad_parcial', false);
});

test('dos movimientos de la misma cotizacion muestran la misma utilidad total', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = clienteYArticuloConCosto($user, 60.00, 100.00);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $cotizacionId = $this->actingAs($user)->postJson('/api/v1/cotizaciones', [
        'cliente_id' => $cliente->id,
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ])->assertCreated()->json('data.id');

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacionId}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => '2026-08-04',
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacionId}/pagos", [
        'tipo' => 'saldo',
        'fecha_pago' => '2026-08-05',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $movimientos = $this->actingAs($user)->getJson('/api/v1/movimientos')->json('data');

    expect($movimientos)->toHaveCount(2);
    foreach ($movimientos as $movimiento) {
        expect($movimiento['documento_origen']['utilidad'])->toBe(80);
    }
});

test('el movimiento de un pago de pedido enlaza al pedido y expone su utilidad', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'costo_con_descuento' => 60.00,
        'precio_unitario_sin_iva' => 100.00,
    ]);
    Existencia::factory()->create(['articulo_id' => $articulo->id, 'existencia' => 10]);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $pedidoId = $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'María Pérez',
        'cliente_telefono' => '5512345678',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('pedido_lineas', [
        'pedido_id' => $pedidoId,
        'costo_unitario' => 60.00,
    ]);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedidoId}/pagos", [
        'fecha_pago' => '2026-08-04',
        'monto' => 232.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.es_automatico', true)
        ->assertJsonPath('data.0.documento_origen.tipo', 'pedido')
        ->assertJsonPath('data.0.documento_origen.etiqueta', 'PED-00001')
        ->assertJsonPath('data.0.documento_origen.ruta', 'pedidos-detalle')
        ->assertJsonPath('data.0.documento_origen.id', $pedidoId)
        ->assertJsonPath('data.0.documento_origen.utilidad', 80)
        ->assertJsonPath('data.0.documento_origen.utilidad_parcial', false);
});

test('un pedido con lineas libres mezcladas expone su utilidad marcada como parcial', function () {
    $user = User::factory()->create();
    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();
    $articulo = Articulo::factory()->for($user)->for($catalogo)->create([
        'costo_con_descuento' => 60.00,
        'precio_unitario_sin_iva' => 100.00,
    ]);
    Existencia::factory()->create(['articulo_id' => $articulo->id, 'existencia' => 10]);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    $pedidoId = $this->actingAs($user)->postJson('/api/v1/pedidos', [
        'cliente_nombre' => 'María Pérez',
        'cliente_telefono' => '5512345678',
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
                'cantidad' => 1,
                'descripcion' => 'Grabado especial a mano',
                'precio_unitario' => 150.00,
                'tasa_iva' => '16',
            ],
        ],
        'total' => 406.00,
    ])->assertCreated()->json('data.id');

    $this->assertDatabaseHas('pedido_lineas', [
        'pedido_id' => $pedidoId,
        'articulo_id' => null,
        'costo_unitario' => null,
    ]);

    $this->actingAs($user)->postJson("/api/v1/pedidos/{$pedidoId}/pagos", [
        'fecha_pago' => '2026-08-04',
        'monto' => 406.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    // La línea libre ($150.00) queda fuera de la suma: utilidad = $200.00 − $120.00 = $80.00.
    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.documento_origen.utilidad', 80)
        ->assertJsonPath('data.0.documento_origen.utilidad_parcial', true);
});

test('una cotizacion sin costo capturado en sus lineas expone utilidad no disponible', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = clienteYArticuloConCosto($user, 60.00, 100.00);
    $cuenta = Cuenta::factory()->for($user)->conSaldo(0)->create();

    // Simula un documento creado antes de que existiera `costo_unitario`: la línea se crea
    // directamente, sin pasar por el endpoint que hoy siempre lo captura.
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::Enviada->value,
        'folio' => 20,
        'total' => 232.00,
    ]);

    $cotizacion->lineas()->create([
        'articulo_id' => $articulo->id,
        'cantidad' => 2,
        'descripcion' => $articulo->nombre,
        'modelo' => $articulo->modelo,
        'precio_unitario' => 100.00,
        'costo_unitario' => null,
        'tasa_iva' => '16',
        'importe' => 200.00,
        'iva_importe' => 32.00,
    ]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => '2026-08-04',
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->getJson('/api/v1/movimientos')
        ->assertJsonPath('data.0.documento_origen.utilidad', null)
        ->assertJsonPath('data.0.documento_origen.utilidad_parcial', false);
});
