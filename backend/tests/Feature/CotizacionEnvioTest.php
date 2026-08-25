<?php

use App\Enums\EstadoCotizacion;
use App\Enums\EstadoOrdenTrabajo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\OrdenTrabajo;
use App\Models\User;

/**
 * Envío directo a domicilio de una Cotización de cliente distribuidor, sin Orden de Trabajo (ver
 * 041-envio-domicilio-direccion-y-distribuidor.md).
 */
function datosEnvioDirectoValidos(array $overrides = []): array
{
    return array_merge([
        'nombre_receptor' => 'Ana López',
        'telefono_receptor' => '5599998888',
        'direccion' => 'Av. Reforma 123, Col. Centro',
        'fecha_recepcion' => now()->toDateString(),
        'hora_recepcion' => '15:30',
        'tarifa' => 'b',
        'forma_pago' => 'por_cobrar',
    ], $overrides);
}

/** Cotización de un cliente distribuidor, con un pago total ya registrado. */
function cotizacionDistribuidorPagada(User $user, array $overrides = []): Cotizacion
{
    $cliente = Cliente::factory()->for($user)->create(['es_distribuidor' => true]);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)
        ->create(array_merge(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00], $overrides));
    $cuenta = Cuenta::factory()->for($user)->create();

    test()->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => now()->toDateString(),
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    return $cotizacion->fresh();
}

test('un cliente no distribuidor no puede generar envio directo', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create(['es_distribuidor' => false]);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => now()->toDateString(),
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())
        ->assertStatus(422);
});

test('una cotizacion distribuidor sin pagos no puede generar envio directo', function () {
    $user = User::factory()->create();
    $cliente = Cliente::factory()->for($user)->create(['es_distribuidor' => true]);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())
        ->assertStatus(422);
});

test('un envio directo por cobrar no genera movimiento de tesoreria', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);
    $movimientosPrevios = Movimiento::count();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos([
        'forma_pago' => 'por_cobrar',
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.envio.forma_pago', 'por_cobrar');
    $response->assertJsonPath('data.envio.monto', 80);
    $response->assertJsonPath('data.envio.direccion', 'Av. Reforma 123, Col. Centro');

    $this->assertDatabaseHas('envios', [
        'documentable_type' => Cotizacion::class,
        'documentable_id' => $cotizacion->id,
        'forma_pago' => 'por_cobrar',
    ]);
    expect(Movimiento::count())->toBe($movimientosPrevios);
});

test('un envio directo prepagado genera un movimiento de ingreso', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);
    $cuenta = Cuenta::factory()->for($user)->create(['saldo_inicial' => 0, 'saldo_actual' => 0]);
    $movimientosPrevios = Movimiento::count();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos([
        'forma_pago' => 'prepagado',
        'tarifa' => 'c',
        'cuenta_id' => $cuenta->id,
    ]));

    $response->assertOk();
    $response->assertJsonPath('data.envio.monto', 120);
    expect((float) $cuenta->fresh()->saldo_actual)->toBe(120.0);
    expect(Movimiento::count())->toBe($movimientosPrevios + 1);
});

test('una cotizacion no puede tener dos envios directos', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())
        ->assertStatus(422);
});

test('marcar entregado el envio directo no toca el estado ni entregado_en de la cotizacion', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio/entregar");

    $response->assertOk();
    $response->assertJsonPath('data.envio.entregado_en', fn ($valor) => $valor !== null);

    $cotizacionFresca = $cotizacion->fresh();
    expect($cotizacionFresca->estado)->toBe(EstadoCotizacion::Pagada);
    expect($cotizacionFresca->entregado_en)->toBeNull();
});

test('un envio directo ya entregado no se puede entregar de nuevo', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())->assertOk();
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio/entregar")->assertOk();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio/entregar")
        ->assertStatus(422);
});

test('una cotizacion de distribuidor puede tener a la vez envio directo y orden de trabajo', function () {
    $user = User::factory()->create();
    $cotizacion = cotizacionDistribuidorPagada($user);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/envio", datosEnvioDirectoValidos())->assertOk();
    $this->actingAs($user)->postJson('/api/v1/ordenes-trabajo', [
        'documentable_type' => 'cotizacion',
        'documentable_id' => $cotizacion->id,
    ])->assertCreated();

    $orden = OrdenTrabajo::where('documentable_id', $cotizacion->id)
        ->where('documentable_type', Cotizacion::class)
        ->firstOrFail();
    expect($orden->estado)->toBe(EstadoOrdenTrabajo::Pendiente);
    expect($cotizacion->fresh()->envio)->not->toBeNull();
});
