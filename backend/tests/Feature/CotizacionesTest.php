<?php

use App\Enums\EstadoCotizacion;
use App\Mail\CotizacionEnviadaMail;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\Cuenta;
use App\Models\Factura;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\FacturapiService;
use Facturapi\Exceptions\FacturapiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PhpCfdi\Rfc\RfcFaker;

function crearClienteYArticuloParaCotizacion(User $user, array $overridesArticulo = []): array
{
    $cliente = Cliente::factory()->for($user)->create([
        'rfc' => (new RfcFaker)->mexicanRfcMoral(),
        'razon_social' => 'Comercializadora Ejemplo SA de CV',
        'regimen_fiscal' => '601',
        'codigo_postal_fiscal' => '20000',
        'correo_contacto' => 'cliente@ejemplo.com',
        'telefono' => '5512345678',
    ]);

    $proveedor = Proveedor::factory()->for($user)->create();
    $catalogo = Catalogo::factory()->for($user)->for($proveedor)->create();

    $articulo = Articulo::factory()->for($user)->for($catalogo)->create(array_merge([
        'nombre' => 'Laptop 14 pulgadas',
        'modelo' => 'MOD-1234',
        'clave_prod_serv' => '43211503',
        'clave_unidad' => 'H87',
        'objeto_imp' => '02',
        'precio_unitario_sin_iva' => 100.00,
    ], $overridesArticulo));

    return [$cliente, $articulo];
}

function datosCotizacionValidos(Cliente $cliente, Articulo $articulo, array $overrides = []): array
{
    return array_merge([
        'cliente_id' => $cliente->id,
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

test('un invitado no puede acceder a cotizaciones', function () {
    $this->getJson('/api/v1/cotizaciones')->assertUnauthorized();
});

test('crear una cotizacion la deja en borrador con folio y totales calculados', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', datosCotizacionValidos($cliente, $articulo));

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'borrador');
    $response->assertJsonPath('data.folio', 1);
    $response->assertJsonPath('data.subtotal', 200);
    $response->assertJsonPath('data.total_iva_16', 32);
    $response->assertJsonPath('data.total', 232);
    $this->assertDatabaseHas('cotizaciones', ['user_id' => $user->id, 'estado' => 'borrador', 'folio' => 1]);
});

test('si el total no coincide con lo calculado la peticion se rechaza', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', datosCotizacionValidos($cliente, $articulo, ['total' => 999]));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('total');
});

test('no se puede cotizar con un cliente ajeno', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [, $articulo] = crearClienteYArticuloParaCotizacion($user);
    [$clienteAjeno] = crearClienteYArticuloParaCotizacion($otro);

    $response = $this->actingAs($user)->postJson('/api/v1/cotizaciones', datosCotizacionValidos($clienteAjeno, $articulo));

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('cliente_id');
});

test('enviar por correo cambia el estado de borrador a enviada', function () {
    Mail::fake();
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/enviar", [
        'canal' => 'correo',
        'destinatarios' => ['cliente@ejemplo.com'],
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id, 'estado' => 'enviada']);
    Mail::assertSent(CotizacionEnviadaMail::class);
});

test('el canal whatsapp ya no existe en el envio del servidor', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create();

    // Ese envío lo comparte el aparato del usuario con el PDF que descarga (029-pwa-mostrador.md).
    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/enviar", [
        'canal' => 'whatsapp',
        'telefono' => '5512345678',
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('canal');
    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id, 'estado' => 'borrador']);
});

test('marcar-enviada cambia el estado de borrador a enviada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/marcar-enviada");

    $response->assertOk();
    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id, 'estado' => 'enviada']);
});

test('marcar-enviada no retrocede una cotizacion ya pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::Pagada->value,
    ]);

    // Compartirle otra vez la misma cotización a un cliente es normal y no tiene por qué fallar.
    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/marcar-enviada");

    $response->assertOk();
    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id, 'estado' => 'pagada']);
});

test('marcar-enviada de otro usuario responde 404', function () {
    $user = User::factory()->create();
    $otro = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($otro);
    $cotizacion = Cotizacion::factory()->for($otro)->for($cliente)->create();

    $this->actingAs($user)
        ->postJson("/api/v1/cotizaciones/{$cotizacion->id}/marcar-enviada")
        ->assertNotFound();
});

test('un pago que cubre el total marca la cotizacion como pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => now()->toDateString(),
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'pagada');
    $response->assertJsonPath('data.total_pagado', 232);
    $this->assertDatabaseHas('cotizacion_pagos', ['cotizacion_id' => $cotizacion->id, 'monto' => 232.00, 'tipo' => 'pago_total']);
});

test('un pago parcial no marca la cotizacion como pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'enviada');
    $response->assertJsonPath('data.saldo_pendiente', 132);
});

test('un anticipo seguido de saldo cubre el total y marca la cotizacion como pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'saldo',
        'fecha_pago' => now()->toDateString(),
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'pagada');
    $response->assertJsonPath('data.total_pagado', 232);
    $this->assertDatabaseHas('cotizacion_pagos', ['cotizacion_id' => $cotizacion->id, 'monto' => 132.00, 'tipo' => 'saldo']);
});

test('un segundo anticipo para la misma cotizacion es rechazado', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 50.00,
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('tipo');
});

test('un anticipo mayor al saldo pendiente es rechazado', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 300.00,
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('monto');
});

test('el monto enviado para saldo o pago total se ignora y se autocalcula el saldo pendiente', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'pago_total',
        'fecha_pago' => now()->toDateString(),
        'monto' => 1.00,
        'cuenta_id' => $cuenta->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.total_pagado', 232);
    $this->assertDatabaseHas('cotizacion_pagos', ['cotizacion_id' => $cotizacion->id, 'monto' => 232.00, 'tipo' => 'pago_total']);
});

test('no se puede marcar como entregada una cotizacion que no esta pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value]);

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/entregar")->assertStatus(422);
});

test('marcar como entregada una cotizacion pagada', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Pagada->value]);

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/entregar");

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'producto_entregado');
});

test('una cotizacion pagada no puede editarse ni eliminarse', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Pagada->value]);

    $this->actingAs($user)->putJson("/api/v1/cotizaciones/{$cotizacion->id}", [])->assertStatus(422);
    $this->actingAs($user)->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}")->assertStatus(422);
});

test('editar una cotizacion enviada la regresa a borrador', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value]);

    $response = $this->actingAs($user)->putJson(
        "/api/v1/cotizaciones/{$cotizacion->id}",
        datosCotizacionValidos($cliente, $articulo),
    );

    $response->assertOk();
    $response->assertJsonPath('data.estado', 'borrador');
});

test('duplicar una cotizacion crea una copia en borrador sin pagos ni factura', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);
    $original = $this->actingAs($user)->postJson('/api/v1/cotizaciones', datosCotizacionValidos($cliente, $articulo))->json('data');

    $response = $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$original['id']}/duplicar");

    $response->assertCreated();
    $response->assertJsonPath('data.estado', 'borrador');
    $response->assertJsonPath('data.folio', 2);
    $response->assertJsonPath('data.total', 232);
    $response->assertJsonPath('data.factura_id', null);
    expect($response->json('data.lineas'))->toHaveCount(1);
});

test('facturar desde una cotizacion vincula la factura resultante', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = $this->actingAs($user)->postJson('/api/v1/cotizaciones', datosCotizacionValidos($cliente, $articulo))->json('data');

    $this->mock(FacturapiService::class, function ($mock) {
        $mock->shouldReceive('timbrarFactura')->once()->andThrow(new FacturapiException('error de prueba'));
    });

    $payload = [
        'cliente_id' => $cliente->id,
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'lineas' => [[
            'articulo_id' => $articulo->id,
            'cantidad' => 2,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            'precio_unitario' => 100.00,
            'tasa_iva' => '16',
        ]],
        'total' => 232.00,
        'cotizacion_id' => $cotizacion['id'],
    ];

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', $payload);

    $response->assertCreated();
    $facturaId = $response->json('data.id');
    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion['id'], 'factura_id' => $facturaId]);
});

test('no se puede reutilizar una cotizacion que ya tiene factura asociada', function () {
    $user = User::factory()->create();
    [$cliente, $articulo] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['factura_id' => Factura::factory()->for($user)->for($cliente)->create()->id]);

    $payload = array_merge(datosCotizacionValidos($cliente, $articulo), [
        'uso_cfdi' => 'G03',
        'forma_pago' => '03',
        'metodo_pago' => 'PUE',
        'cotizacion_id' => $cotizacion->id,
    ]);

    $response = $this->actingAs($user)->postJson('/api/v1/facturas', $payload);

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors('cotizacion_id');
});

test('el listado de cotizaciones filtra por cliente, rfc, folio y estado', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    [$otroCliente] = crearClienteYArticuloParaCotizacion($user);

    Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Pagada->value]);
    Cotizacion::factory()->for($user)->for($otroCliente)->create(['estado' => EstadoCotizacion::Borrador->value]);

    $response = $this->actingAs($user)->getJson('/api/v1/cotizaciones?estado=pagada');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.estado', 'pagada');
});

test('el buscador unico del listado de cotizaciones encuentra por folio, razon social y rfc', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    [$otroCliente] = crearClienteYArticuloParaCotizacion($user);

    $cliente->update(['razon_social' => 'Herrajes del Bajio SA de CV']);
    $otroCliente->update(['razon_social' => 'Papeleria Central SA de CV']);

    $buscada = Cotizacion::factory()->for($user)->for($cliente)->create(['folio' => 314]);
    Cotizacion::factory()->for($user)->for($otroCliente)->create(['folio' => 315]);

    // Un solo campo en el celular, tres columnas en el servidor: el buscador tiene que dar con la
    // misma cotizacion escriba lo que escriba el usuario (ver 031-mostrador-consulta.md).
    foreach (['314', 'Herrajes', $cliente->rfc] as $texto) {
        $response = $this->actingAs($user)->getJson('/api/v1/cotizaciones?search='.urlencode($texto));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $buscada->id);
    }
});

test('el buscador unico convive con los filtros por columna del escritorio', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);

    $cliente->update(['razon_social' => 'Herrajes del Bajio SA de CV']);

    Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Pagada->value]);
    Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Borrador->value]);

    $response = $this->actingAs($user)->getJson('/api/v1/cotizaciones?search=Herrajes&estado=pagada');

    $response->assertOk();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.estado', 'pagada');
});

test('el filtro de fecha usa el dia calendario de la zona horaria del negocio, no UTC', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);

    // 2026-08-03 04:16 UTC equivale a 2026-08-02 22:16 en America/Mexico_City (UTC-6): de
    // noche del dia anterior en hora del negocio, aunque la columna created_at ya cayo en el
    // dia siguiente en UTC.
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create();
    DB::table('cotizaciones')->where('id', $cotizacion->id)->update(['created_at' => '2026-08-03 04:16:00']);

    $filtroDiaUtc = $this->actingAs($user)->getJson('/api/v1/cotizaciones?fecha_desde=2026-08-03&fecha_hasta=2026-08-03');
    $filtroDiaUtc->assertOk();
    $filtroDiaUtc->assertJsonCount(0, 'data');

    $filtroDiaNegocio = $this->actingAs($user)->getJson('/api/v1/cotizaciones?fecha_desde=2026-08-02&fecha_hasta=2026-08-02');
    $filtroDiaNegocio->assertOk();
    $filtroDiaNegocio->assertJsonCount(1, 'data');
    $filtroDiaNegocio->assertJsonPath('data.0.id', $cotizacion->id);
});

test('una cotizacion enviada sin pagos se puede eliminar', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value]);

    $this->actingAs($user)->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}")->assertNoContent();

    $this->assertDatabaseMissing('cotizaciones', ['id' => $cotizacion->id]);
});

test('no se puede eliminar una cotizacion con pagos registrados', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $cuenta = Cuenta::factory()->for($user)->create();

    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$cotizacion->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();

    $this->actingAs($user)->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}")->assertStatus(422);

    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id]);
});

test('no se puede eliminar una cotizacion que ya genero una factura', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create([
        'estado' => EstadoCotizacion::Enviada->value,
        'factura_id' => Factura::factory()->for($user)->for($cliente)->create()->id,
    ]);

    $this->actingAs($user)->deleteJson("/api/v1/cotizaciones/{$cotizacion->id}")->assertStatus(422);

    $this->assertDatabaseHas('cotizaciones', ['id' => $cotizacion->id]);
});

test('purgar vencidas borra las cotizaciones sin movimiento en 30 dias y respeta las demas', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $cuenta = Cuenta::factory()->for($user)->create();

    $vieja = function (array $atributos) use ($user, $cliente) {
        $cotizacion = Cotizacion::factory()->for($user)->for($cliente)->create($atributos);
        DB::table('cotizaciones')->where('id', $cotizacion->id)->update(['updated_at' => now()->subDays(31)]);

        return $cotizacion;
    };

    $borradorVencida = $vieja(['estado' => EstadoCotizacion::Borrador->value]);
    $enviadaVencida = $vieja(['estado' => EstadoCotizacion::Enviada->value]);
    $pagadaVencida = $vieja(['estado' => EstadoCotizacion::Pagada->value]);
    $facturadaVencida = $vieja([
        'estado' => EstadoCotizacion::Enviada->value,
        'factura_id' => Factura::factory()->for($user)->for($cliente)->create()->id,
    ]);
    $reciente = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value]);

    $conAnticipo = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value, 'total' => 232.00]);
    $this->actingAs($user)->postJson("/api/v1/cotizaciones/{$conAnticipo->id}/pagos", [
        'tipo' => 'anticipo',
        'fecha_pago' => now()->toDateString(),
        'monto' => 100.00,
        'cuenta_id' => $cuenta->id,
    ])->assertOk();
    DB::table('cotizaciones')->where('id', $conAnticipo->id)->update(['updated_at' => now()->subDays(31)]);

    $this->artisan('cotizaciones:purgar-vencidas')->assertExitCode(0);

    $this->assertDatabaseMissing('cotizaciones', ['id' => $borradorVencida->id]);
    $this->assertDatabaseMissing('cotizaciones', ['id' => $enviadaVencida->id]);
    $this->assertDatabaseHas('cotizaciones', ['id' => $pagadaVencida->id]);
    $this->assertDatabaseHas('cotizaciones', ['id' => $facturadaVencida->id]);
    $this->assertDatabaseHas('cotizaciones', ['id' => $reciente->id]);
    $this->assertDatabaseHas('cotizaciones', ['id' => $conAnticipo->id]);

    // Idempotente: sin cotizaciones vencidas nuevas, la segunda corrida no borra nada.
    $this->artisan('cotizaciones:purgar-vencidas')->assertExitCode(0);
    $this->assertDatabaseCount('cotizaciones', 4);
});

test('el detalle expone la fecha de caducidad y si se puede eliminar', function () {
    $user = User::factory()->create();
    [$cliente] = crearClienteYArticuloParaCotizacion($user);
    $enviada = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Enviada->value]);
    $pagada = Cotizacion::factory()->for($user)->for($cliente)->create(['estado' => EstadoCotizacion::Pagada->value]);

    $respuestaEnviada = $this->actingAs($user)->getJson("/api/v1/cotizaciones/{$enviada->id}");
    $respuestaEnviada->assertOk();
    $respuestaEnviada->assertJsonPath('data.puede_eliminarse', true);
    expect($respuestaEnviada->json('data.caduca_el'))->not->toBeNull();

    $respuestaPagada = $this->actingAs($user)->getJson("/api/v1/cotizaciones/{$pagada->id}");
    $respuestaPagada->assertOk();
    $respuestaPagada->assertJsonPath('data.puede_eliminarse', false);
    $respuestaPagada->assertJsonPath('data.caduca_el', null);
});
