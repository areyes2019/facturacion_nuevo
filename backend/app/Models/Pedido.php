<?php

namespace App\Models;

use App\Enums\EstadoPedido;
use App\Enums\TipoDescuento;
use App\Models\Concerns\CalculaUtilidadVenta;
use Carbon\CarbonInterface;
use Database\Factories\PedidoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Str;

/**
 * Venta de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * `$table` explícito porque Eloquent pluraliza en inglés y de `Pedido` inferiría `pedidos` por
 * casualidad, no por regla — misma lección ya pagada en 005, 008, 012 y 017.
 *
 * Dos columnas viven aquí pero deliberadamente **fuera** de `#[Fillable]`, mismo criterio que
 * `articulos.imagen_ruta` en 020 y `cotizaciones.datos_bancarios` en 026: `autofactura_token` y
 * `autofactura_error` los escribe el sistema al cobrar y al timbrar, no el cliente HTTP.
 *
 * **No hay columna para el ticket.** La imagen no se guarda: se dibuja cada vez que se pide y se
 * desecha, porque el folio, el cliente, las líneas y los pagos ya viven aquí y la imagen solo los
 * pinta (ver `TicketPedidoService`).
 */
#[Fillable([
    'folio',
    'cliente_nombre',
    'cliente_telefono',
    'cliente_correo',
    'estado',
    'descuento_global_tipo',
    'descuento_global_valor',
    'subtotal',
    'total_descuento',
    'total_iva_16',
    'total_iva_0',
    'total_exento',
    'ajuste_al_peso',
    'total',
    'entregado_en',
    'factura_id',
])]
class Pedido extends Model
{
    use CalculaUtilidadVenta;

    /** @use HasFactory<PedidoFactory> */
    use HasFactory;

    protected $table = 'pedidos';

    /**
     * Ventana durante la cual el backend acepta deshacer una entrega.
     *
     * Es más ancha que los 10 segundos del botón a propósito: el botón mide la impaciencia del
     * usuario, este límite evita que un "Deshacer" disparado desde una pestaña olvidada revierta
     * mañana una entrega legítima.
     */
    public const MINUTOS_PARA_DESHACER_ENTREGA = 5;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(PedidoLinea::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(PedidoPago::class);
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /** Orden de Trabajo de Producción, si este pedido requiere fabricación (ver 038). */
    public function ordenTrabajo(): MorphOne
    {
        return $this->morphOne(OrdenTrabajo::class, 'documentable');
    }

    /** Folio con el formato en que se imprime: `PED-00042`. */
    public function folioFormateado(): string
    {
        return 'PED-'.$this->numeroTicket();
    }

    /** El "No. de ticket" tal como sale en el ticket y en la etiqueta. */
    public function numeroTicket(): string
    {
        return str_pad((string) $this->folio, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Usa lo que ya venga cargado (`with`/`withSum`) antes de gastar una consulta por fila: el
     * listado pinta el saldo de quince pedidos y sin esto serían quince consultas de más.
     */
    public function totalPagado(): float
    {
        if ($this->relationLoaded('pagos')) {
            return round((float) $this->pagos->sum('monto'), 2);
        }

        if ($this->pagos_sum_monto !== null) {
            return round((float) $this->pagos_sum_monto, 2);
        }

        return round((float) $this->pagos()->sum('monto'), 2);
    }

    public function saldoPendiente(): float
    {
        return round(max(0, (float) $this->total - $this->totalPagado()), 2);
    }

    /**
     * ¿La entrega de este pedido registró un cobro?
     *
     * Es lo que hace que "Deshacer" solo exista en el camino que cierra sin preguntar: una entrega
     * que cobró ya pasó por la confirmación del usuario, con el nombre del cliente y el saldo a la
     * vista, así que revertirla a ciegas no arregla un descuido — lo provoca. Ese pago se corrige
     * desde el detalle del pedido, como cualquier otro.
     *
     * Se pregunta por la bandera y no por el último pago de la lista: un pago manual capturado en
     * el mismo segundo lo desplazaría del final.
     */
    public function entregaRegistroCobro(): bool
    {
        return $this->pagos()->where('registrado_al_entregar', true)->exists();
    }

    public function tienePagos(): bool
    {
        if ($this->relationLoaded('pagos')) {
            return $this->pagos->isNotEmpty();
        }

        if ($this->pagos_count !== null) {
            return (int) $this->pagos_count > 0;
        }

        return $this->pagos()->exists();
    }

    /**
     * Un pedido se puede tirar mientras nadie haya pagado nada. Con pagos de por medio hay
     * movimientos de Tesorería colgando, y quien sabe revertirlos —y recalcular el saldo de la
     * cuenta— es el endpoint de pagos, no el `DELETE` del documento (mismo criterio que 008).
     */
    public function puedeEliminarse(): bool
    {
        return $this->estado === EstadoPedido::Pendiente
            && $this->factura_id === null
            && ! $this->tienePagos();
    }

    /**
     * Reescribe el estado a partir de lo que lleva pagado. No toca un pedido ya entregado: borrar
     * un pago no des-entrega la mercancía, y quien sí puede regresarlo es `deshacer-entrega`.
     */
    public function sincronizarEstadoConPagos(): void
    {
        if ($this->estado === EstadoPedido::Entregado) {
            return;
        }

        $this->update([
            'estado' => EstadoPedido::segunPagos((float) $this->total, $this->totalPagado())->value,
        ]);
    }

    /**
     * Genera el token del enlace público de autofactura si el pedido ya está totalmente pagado y
     * aún no lo tiene. Es idempotente: un pedido conserva su enlace aunque se recalcule el estado.
     *
     * 64 caracteres al azar y no el `id` porque el enlace viaja sin sesión: con el id a la vista,
     * cualquiera podría cambiar el número a mano y facturar el pedido de otro cliente, o ir
     * probando números hasta encontrar pedidos sin facturar.
     */
    public function asegurarTokenAutofactura(): void
    {
        if ($this->autofactura_token !== null || $this->totalPagado() < (float) $this->total) {
            return;
        }

        $this->autofactura_token = Str::random(64);
        $this->save();
    }

    /**
     * Último instante en que el enlace de autofactura sirve: el cierre del mes de la venta.
     *
     * Un CFDI se emite en el periodo de la operación; pasado el mes, timbrar esa venta ya no
     * procede y es mejor que el cliente se tope con un aviso claro que con un rechazo del SAT.
     */
    public function autofacturaVenceEl(): CarbonInterface
    {
        return ($this->created_at ?? now())->copy()->endOfMonth();
    }

    /**
     * Por qué el enlace de autofactura no sirve, o `null` si sirve. Una sola función para que la
     * página pública y el detalle del pedido no puedan discrepar.
     */
    public function motivoAutofacturaNoDisponible(): ?string
    {
        if ($this->factura_id !== null) {
            return 'Este pedido ya fue facturado.';
        }

        if ($this->totalPagado() < (float) $this->total) {
            return 'Este pedido todavía tiene saldo pendiente.';
        }

        if (now()->greaterThan($this->autofacturaVenceEl())) {
            return 'El plazo para facturar esta compra terminó al cerrar el mes en que se realizó. Contacta al negocio.';
        }

        return null;
    }

    /**
     * Dirección que codifica el QR: la pantalla de entrega, absoluta.
     *
     * **Un solo código por pedido**, idéntico en el ticket y en la etiqueta. Absoluta y no un dato
     * suelto por dos razones: la app de cámara del celular la abre sola sin que nadie copie ni
     * pegue nada, y el escáner de la aplicación instalada (029) lee este mismo QR con su propio
     * lector y actúa sobre el id que trae la ruta, sin reimprimir una sola etiqueta.
     */
    public function urlEntrega(): string
    {
        return rtrim((string) config('app.frontend_url'), '/')."/pedidos/{$this->id}/entregar";
    }

    public function urlAutofactura(): ?string
    {
        if ($this->autofactura_token === null) {
            return null;
        }

        return rtrim((string) config('app.frontend_url'), '/')."/autofactura/{$this->autofactura_token}";
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoPedido::class,
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'total_iva_0' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'ajuste_al_peso' => 'decimal:2',
            'total' => 'decimal:2',
            'entregado_en' => 'datetime',
        ];
    }
}
