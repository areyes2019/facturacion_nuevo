<?php

use App\Http\Controllers\ArticuloController;
use App\Http\Controllers\ArticuloImagenController;
use App\Http\Controllers\AutofacturaController;
use App\Http\Controllers\CatalogoController;
use App\Http\Controllers\CatalogoProveedorController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ConfiguracionController;
use App\Http\Controllers\ConstanciaFiscalController;
use App\Http\Controllers\ContactoLandingController;
use App\Http\Controllers\CotizacionController;
use App\Http\Controllers\CotizacionEnvioController;
use App\Http\Controllers\CuentaController;
use App\Http\Controllers\DatoBancarioController;
use App\Http\Controllers\EmisorController;
use App\Http\Controllers\EnvioController;
use App\Http\Controllers\FacturaController;
use App\Http\Controllers\InventarioController;
use App\Http\Controllers\MovimientoController;
use App\Http\Controllers\OrdenCompraController;
use App\Http\Controllers\OrdenTrabajoController;
use App\Http\Controllers\OrdenTrabajoImagenController;
use App\Http\Controllers\PedidoController;
use App\Http\Controllers\ProveedorController;
use App\Http\Controllers\TransferenciaController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(base_path('routes/auth.php'));

// Sin auth:sanctum: PDF de orden de compra protegido por firma temporal de la URL (`signed`), no
// por sesión — exclusivo para que Twilio lo descargue al enviar el WhatsApp (ver
// 012-ordenes-compra.md). La cotización tenía uno igual hasta que su WhatsApp pasó a compartirse
// desde el aparato del usuario (ver 029-pwa-mostrador.md).
Route::get('ordenes-compra/{ordenCompra}/pdf-publico', [OrdenCompraController::class, 'pdfPublico'])
    ->name('ordenes-compra.pdf-publico')
    ->middleware('signed');

// Sin auth:sanctum: portal de autofacturación del cliente de mostrador, que no tiene cuenta en el
// sistema (ver 027-venta-mostrador-ticket.md). Lo protege el token de 64 caracteres del pedido, no
// una firma temporal: el enlace tiene que sobrevivir hasta el cierre del mes.
//
// Y el formulario de contacto de la landing pública (ver 037-landing-prosello.md), llamado desde
// otro origen (prosello.com.mx) por quien todavía no es cliente. Son las únicas rutas que
// cualquiera en internet puede llamar, y por eso las únicas con límite de peticiones explícito.
Route::middleware('throttle:20,1')->group(function () {
    Route::get('autofactura/{token}', [AutofacturaController::class, 'show']);
    Route::post('autofactura/{token}', [AutofacturaController::class, 'store']);
});

Route::middleware('throttle:10,1')->post('contacto', [ContactoLandingController::class, 'store']);

// Los dos catálogos que el cliente necesita para llenar sus datos fiscales en el portal público.
// Salen del grupo autenticado porque los llama una página abierta, y no revelan nada del negocio:
// son las mismas listas que el SAT publica (`phpcfdi/sat-catalogos`), sin una sola consulta a la
// base de datos. Los demás catálogos sí se quedan detrás del login.
//
// Con un límite más holgado que el de autofactura: el buscador de uso de CFDI hace una petición por
// búsqueda mientras el cliente escribe, y 20 por minuto se agotarían tecleando.
Route::middleware('throttle:60,1')->prefix('catalogos')->group(function () {
    Route::get('regimenes-fiscales', [CatalogoController::class, 'regimenesFiscales']);
    Route::get('usos-cfdi', [CatalogoController::class, 'usosCfdi']);
});

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Antes del apiResource para que no la capture ninguna ruta más general. El límite de uso
    // protege la IP del servidor: el bloqueo que aplicaría el SAT por exceso de consultas caería
    // sobre todo el sistema, no sobre un usuario (ver 016-constancia-situacion-fiscal-qr.md).
    Route::post('clientes/constancia', [ConstanciaFiscalController::class, 'analizar'])
        ->middleware('throttle:10,1');

    Route::apiResource('clientes', ClienteController::class);
    Route::apiResource('proveedores', ProveedorController::class)
        ->parameters(['proveedores' => 'proveedor']);

    Route::get('articulos/exportar-csv', [ArticuloController::class, 'exportarCsv']);

    // Selección persistente entre páginas (ver 021-mantenimiento-articulos-catalogos.md): respalda
    // el botón "Seleccionar todo lo filtrado". Misma razón para ir antes del apiResource que
    // exportar-csv, arriba.
    Route::get('articulos/ids-filtrados', [ArticuloController::class, 'idsFiltrados']);

    // Borrado en lote (ver 021-mantenimiento-articulos-catalogos.md). Es `POST` y no `DELETE` con
    // cuerpo porque el cuerpo de un `DELETE` no está garantizado de punta a punta: proxies y
    // servidores intermedios pueden descartarlo, y perder los identificadores por el camino aquí
    // significaría no borrar nada o fallar de forma difícil de leer.
    Route::post('articulos/eliminar-lote', [ArticuloController::class, 'eliminarLote']);

    // Mover en lote a otro catálogo (ver 034-filtro-catalogo-y-mover-lote-articulos.md). Mismo
    // molde y misma razón de estar antes del apiResource que eliminar-lote, arriba.
    Route::post('articulos/mover-lote', [ArticuloController::class, 'moverLote']);

    // Lista de precios en PDF de los artículos seleccionados (ver 028-lista-precios-pdf.md). Va
    // aquí por la misma razón que eliminar-lote y mover-lote: es una ruta específica, no un
    // recurso de {articulo}.
    Route::post('articulos/lista-precios', [ArticuloController::class, 'listaPrecios']);

    // Imágenes de artículo (ver 020-imagenes-articulos.md). Van ANTES del apiResource: con
    // {articulo}/imagen después, el resource no las capturaría, pero se mantiene el orden por la
    // misma razón que exportar-csv arriba — las rutas más específicas primero, sin excepciones que
    // haya que recordar.
    Route::get('articulos/{articulo}/imagen', [ArticuloImagenController::class, 'show']);
    Route::post('articulos/{articulo}/imagen', [ArticuloImagenController::class, 'store']);
    Route::delete('articulos/{articulo}/imagen', [ArticuloImagenController::class, 'destroy']);

    Route::apiResource('articulos', ArticuloController::class);

    // Existencias (ver 017-inventario.md). Las rutas estáticas van ANTES del binding {articulo},
    // o Laravel las captura como si "auditoria" fuera un artículo — mismo cuidado que arriba con
    // articulos/exportar-csv.
    Route::get('inventario/auditoria', [InventarioController::class, 'auditoria']);
    Route::post('inventario/generar-ordenes-compra', [InventarioController::class, 'generarOrdenesCompra']);
    Route::get('inventario', [InventarioController::class, 'index']);
    Route::put('inventario/{articulo}/parametros', [InventarioController::class, 'parametros']);
    Route::post('inventario/{articulo}/ajuste', [InventarioController::class, 'ajuste']);
    Route::delete('inventario/{articulo}', [InventarioController::class, 'destroy']);
    Route::get('inventario/{articulo}/movimientos', [InventarioController::class, 'movimientos']);

    // Pizarrón de ajustes globales del usuario (ver 014-costo-elaboracion-goma.md). La ruta de
    // impacto va antes del GET base para que no la capture ninguna ruta más general.
    Route::get('configuracion/impacto-precios', [ConfiguracionController::class, 'impactoPrecios']);
    Route::get('configuracion', [ConfiguracionController::class, 'index']);
    Route::put('configuracion', [ConfiguracionController::class, 'update']);

    // Datos fiscales del emisor (ver 019-formato-pdf-documentos.md). Únicos endpoints del sistema
    // que NO se scopean por usuario: el emisor es uno solo para toda la instalación, porque el
    // timbrado usa una única llave de Facturapi.
    Route::get('emisor', [EmisorController::class, 'show']);
    Route::put('emisor', [EmisorController::class, 'update']);
    Route::get('emisor/logo/{tipo}', [EmisorController::class, 'verLogo']);
    Route::post('emisor/logo', [EmisorController::class, 'subirLogo']);
    Route::delete('emisor/logo/{tipo}', [EmisorController::class, 'eliminarLogo']);

    // Cuentas bancarias que se imprimen en la cotización (ver 026-datos-bancarios-cotizacion.md).
    // Tampoco se scopean por usuario, por lo mismo que el emisor de arriba: son del negocio que
    // emite. "orden" va antes del {dato} para que el binding no la tome por un identificador,
    // igual que cuentas/saldos más abajo.
    Route::put('datos-bancarios/orden', [DatoBancarioController::class, 'reordenar']);
    Route::get('datos-bancarios', [DatoBancarioController::class, 'index']);
    Route::post('datos-bancarios', [DatoBancarioController::class, 'store']);
    Route::put('datos-bancarios/{dato}', [DatoBancarioController::class, 'update']);
    Route::delete('datos-bancarios/{dato}', [DatoBancarioController::class, 'destroy']);
    Route::get('datos-bancarios/{dato}/logo', [DatoBancarioController::class, 'verLogo']);
    Route::post('datos-bancarios/{dato}/logo', [DatoBancarioController::class, 'subirLogo']);
    Route::delete('datos-bancarios/{dato}/logo', [DatoBancarioController::class, 'eliminarLogo']);

    // Prefijo "catalogos-proveedor" (no "catalogos") para no chocar con el grupo de catálogos SAT
    // de referencia registrado más abajo bajo /catalogos (ver 009-catalogos.md).
    Route::apiResource('catalogos-proveedor', CatalogoProveedorController::class)
        ->parameters(['catalogos-proveedor' => 'catalogo']);
    Route::post('catalogos-proveedor/{catalogo}/articulos/importar-csv', [ArticuloController::class, 'importarCsv']);
    // Misma idea que la importación CSV de arriba, con imágenes: una carga masiva dirigida a un
    // catálogo concreto (ver 020-imagenes-articulos.md).
    Route::post('catalogos-proveedor/{catalogo}/articulos/imagenes', [ArticuloImagenController::class, 'cargaMasiva']);
    Route::post('catalogos-proveedor/{catalogo}/impacto-precios', [CatalogoProveedorController::class, 'impactoPrecios']);
    // Aumento porcentual del costo de todos los artículos del catálogo (ver
    // 021-mantenimiento-articulos-catalogos.md).
    Route::post('catalogos-proveedor/{catalogo}/aumentar-costos', [CatalogoProveedorController::class, 'aumentarCostos']);

    Route::apiResource('facturas', FacturaController::class);

    Route::post('facturas/{factura}/timbrar', [FacturaController::class, 'timbrar']);
    Route::post('facturas/{factura}/cancelar', [FacturaController::class, 'cancelar']);
    Route::get('facturas/{factura}/xml', [FacturaController::class, 'xml']);
    Route::get('facturas/{factura}/pdf', [FacturaController::class, 'pdf']);
    Route::post('facturas/{factura}/enviar-correo', [FacturaController::class, 'enviarCorreo']);
    Route::post('facturas/{factura}/complemento-pago', [FacturaController::class, 'complementoPago']);

    Route::apiResource('cotizaciones', CotizacionController::class)
        ->parameters(['cotizaciones' => 'cotizacion']);
    Route::post('cotizaciones/{cotizacion}/enviar', [CotizacionController::class, 'enviar']);
    // Cierre del envío por WhatsApp, que ocurre en el aparato del usuario (ver 029-pwa-mostrador.md).
    Route::post('cotizaciones/{cotizacion}/marcar-enviada', [CotizacionController::class, 'marcarEnviada']);
    Route::post('cotizaciones/{cotizacion}/pagos', [CotizacionController::class, 'pagos']);
    Route::delete('cotizaciones/{cotizacion}/pagos/{pago}', [CotizacionController::class, 'eliminarPago']);
    Route::get('cotizaciones/{cotizacion}/pagos/{pago}/recibo', [CotizacionController::class, 'reciboPago']);
    Route::post('cotizaciones/{cotizacion}/entregar', [CotizacionController::class, 'entregar']);
    Route::post('cotizaciones/{cotizacion}/deshacer-entrega', [CotizacionController::class, 'deshacerEntrega']);
    Route::post('cotizaciones/{cotizacion}/duplicar', [CotizacionController::class, 'duplicar']);
    Route::get('cotizaciones/{cotizacion}/pdf', [CotizacionController::class, 'pdf']);
    // Envío directo a domicilio para distribuidores, sin Orden de Trabajo (ver
    // 041-envio-domicilio-direccion-y-distribuidor.md).
    Route::post('cotizaciones/{cotizacion}/envio', [CotizacionEnvioController::class, 'store']);
    Route::post('cotizaciones/{cotizacion}/envio/entregar', [CotizacionEnvioController::class, 'entregar']);

    // Venta de mostrador (ver 027-venta-mostrador-ticket.md). La búsqueda por teléfono va antes
    // del apiResource para que `pedidos/por-telefono` no se lea como `pedidos/{pedido}`.
    Route::get('pedidos/por-telefono', [PedidoController::class, 'porTelefono']);
    Route::apiResource('pedidos', PedidoController::class);
    Route::get('pedidos/{pedido}/ticket', [PedidoController::class, 'ticket']);
    Route::post('pedidos/{pedido}/pagos', [PedidoController::class, 'pagos']);
    Route::delete('pedidos/{pedido}/pagos/{pago}', [PedidoController::class, 'eliminarPago']);
    Route::post('pedidos/{pedido}/entregar', [PedidoController::class, 'entregar']);
    Route::post('pedidos/{pedido}/deshacer-entrega', [PedidoController::class, 'deshacerEntrega']);

    // Producción (ver 038-produccion-ordenes-trabajo.md). Imágenes antes del apiResource, mismo
    // criterio que articulos/{articulo}/imagen (020).
    Route::get('ordenes-trabajo/{orden}/imagen', [OrdenTrabajoImagenController::class, 'show']);
    Route::post('ordenes-trabajo/{orden}/imagen', [OrdenTrabajoImagenController::class, 'store']);
    Route::delete('ordenes-trabajo/{orden}/imagen', [OrdenTrabajoImagenController::class, 'destroy']);
    Route::get('ordenes-trabajo', [OrdenTrabajoController::class, 'index']);
    Route::post('ordenes-trabajo', [OrdenTrabajoController::class, 'store']);
    Route::get('ordenes-trabajo/{orden}', [OrdenTrabajoController::class, 'show']);
    Route::put('ordenes-trabajo/{orden}', [OrdenTrabajoController::class, 'update']);
    Route::post('ordenes-trabajo/{orden}/iniciar-produccion', [OrdenTrabajoController::class, 'iniciarProduccion']);
    Route::post('ordenes-trabajo/{orden}/marcar-listo', [OrdenTrabajoController::class, 'marcarListo']);
    Route::post('ordenes-trabajo/{orden}/entregar', [OrdenTrabajoController::class, 'entregar']);
    Route::post('ordenes-trabajo/{orden}/envio', [EnvioController::class, 'store']);

    // Órdenes de compra (ver 012-ordenes-compra.md). El `->parameters()` es obligatorio: sin él,
    // Laravel singulariza "ordenes-compra" en inglés y genera un parámetro de ruta que rompe el
    // binding implícito de modelo (misma lección de 005 y 008).
    Route::apiResource('ordenes-compra', OrdenCompraController::class)
        ->parameters(['ordenes-compra' => 'ordenCompra']);
    Route::post('ordenes-compra/{ordenCompra}/enviar', [OrdenCompraController::class, 'enviar']);
    Route::post('ordenes-compra/{ordenCompra}/pagar', [OrdenCompraController::class, 'pagar']);
    Route::delete('ordenes-compra/{ordenCompra}/pago', [OrdenCompraController::class, 'cancelarPago']);
    Route::post('ordenes-compra/{ordenCompra}/recibir', [OrdenCompraController::class, 'recibir']);
    Route::post('ordenes-compra/{ordenCompra}/duplicar', [OrdenCompraController::class, 'duplicar']);
    Route::get('ordenes-compra/{ordenCompra}/pdf', [OrdenCompraController::class, 'pdf']);

    // Tesorería (ver 010-tesoreria.md). "saldos" se registra antes del apiResource para que no lo
    // capture el parámetro {cuenta}, igual que articulos/exportar-csv más arriba.
    Route::get('cuentas/saldos', [CuentaController::class, 'saldos']);
    Route::apiResource('cuentas', CuentaController::class);
    Route::apiResource('movimientos', MovimientoController::class);
    Route::post('transferencias', [TransferenciaController::class, 'store']);

    Route::prefix('catalogos')->group(function () {
        Route::get('codigos-postales', [CatalogoController::class, 'codigosPostales']);
        Route::get('claves-prod-serv', [CatalogoController::class, 'clavesProdServ']);
        Route::get('claves-unidad', [CatalogoController::class, 'clavesUnidad']);
        Route::get('objetos-impuesto', [CatalogoController::class, 'objetosImpuesto']);
        Route::get('formas-pago', [CatalogoController::class, 'formasPago']);
        Route::get('motivos-cancelacion', [CatalogoController::class, 'motivosCancelacion']);
        Route::get('metodos-pago', [CatalogoController::class, 'metodosPago']);
    });
});
