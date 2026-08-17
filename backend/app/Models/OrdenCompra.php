<?php

namespace App\Models;

use App\Contracts\DocumentoEnviablePorWhatsApp;
use App\Enums\EstadoOrdenCompra;
use App\Enums\TipoDescuento;
use App\Mail\OrdenCompraEnviadaMail;
use Database\Factories\OrdenCompraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\URL;

/**
 * Documento espejo de la Cotización: donde la cotización le dice a un cliente "esto te vendo, a
 * este precio de venta", la orden le dice a un proveedor "esto te compro, a este costo" (ver
 * 012-ordenes-compra.md). La aritmética es idéntica; lo que cambia es de qué lado del negocio está
 * el dinero.
 */
#[Fillable([
    'proveedor_id',
    'folio',
    'estado',
    'fecha_entrega_esperada',
    'observaciones',
    'descuento_global_tipo',
    'descuento_global_valor',
    'subtotal',
    'total_descuento',
    'total_iva_16',
    'total_iva_0',
    'total_exento',
    'total',
    'cuenta_id',
    'fecha_pago',
])]
class OrdenCompra extends Model implements DocumentoEnviablePorWhatsApp
{
    /** @use HasFactory<OrdenCompraFactory> */
    use HasFactory;

    /**
     * Eloquent pluraliza en inglés y de "OrdenCompra" infiere "orden_compras"; se declara explícito
     * desde el primer commit, igual que el parámetro de ruta en `routes/api.php` (lección ya pagada
     * en 005 y 008, ver 012-ordenes-compra.md, adición técnica 36).
     */
    protected $table = 'ordenes_compra';

    /** Vigencia de la URL firmada que Twilio usa para descargar el PDF adjunto. */
    private const MINUTOS_URL_PUBLICA = 10;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * `withTrashed()` es deliberado: una orden ya `recibida` deja de bloquear el borrado de su
     * proveedor, así que el proveedor puede quedar eliminado (soft delete) mientras la orden sigue
     * existiendo como documento histórico. Sin esto, el PDF y el detalle de esas órdenes se caen
     * al leer los datos del proveedor.
     */
    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class)->withTrashed();
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(OrdenCompraLinea::class);
    }

    /**
     * Cuenta de Tesorería de la que salió el dinero; `null` mientras la orden no esté pagada.
     */
    public function cuenta(): BelongsTo
    {
        return $this->belongsTo(Cuenta::class);
    }

    /**
     * Movimiento de egreso que el pago generó en Tesorería. A diferencia de la cotización —donde
     * el documento origen es cada `CotizacionPago`, porque hay historial— aquí el origen es la
     * orden misma: el pago es único y de contado (ver 012, adición técnica 35).
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    public function estaPagada(): bool
    {
        return $this->cuenta_id !== null && $this->fecha_pago !== null;
    }

    public function folioFormateado(): string
    {
        return 'OC-'.str_pad((string) $this->folio, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Concepto del movimiento automático, generado por el sistema y no editable por el usuario
     * (RN-009 de 010-tesoreria.md).
     */
    public function conceptoMovimiento(): string
    {
        return 'Pago de Orden de compra '.$this->folioFormateado();
    }

    public function vistaPdf(): string
    {
        return 'pdf.orden-compra';
    }

    /**
     * @return array<string, mixed>
     */
    public function datosPdf(): array
    {
        // `lineas.articulo` con `withTrashed`: la Clave SAT del PDF sale del artículo, y las líneas
        // no guardan copia propia. Sin `withTrashed`, dar de baja un artículo haría que la
        // reimpresión de una orden vieja perdiera esa columna (ver 019-formato-pdf-documentos.md).
        // La restricción va aquí y no en la relación `articulo()`, que la usan el listado y la API
        // y que no deben empezar a devolver artículos borrados.
        $this->loadMissing(['proveedor', 'lineas.articulo' => fn ($relacion) => $relacion->withTrashed()]);

        return ['orden' => $this];
    }

    public function nombreArchivoPdf(): string
    {
        return 'orden-compra-'.$this->folio.'.pdf';
    }

    public function mailable(string $pdf): Mailable
    {
        return new OrdenCompraEnviadaMail($this, $pdf);
    }

    public function resumenWhatsApp(): string
    {
        return 'Orden de compra '.$this->folioFormateado().' por un total de $'
            .number_format((float) $this->total, 2).' MXN.';
    }

    public function urlPdfPublico(): string
    {
        return URL::temporarySignedRoute(
            'ordenes-compra.pdf-publico',
            now()->addMinutes(self::MINUTOS_URL_PUBLICA),
            ['ordenCompra' => $this->id],
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenCompra::class,
            'fecha_entrega_esperada' => 'date',
            'fecha_pago' => 'date',
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'total_iva_0' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }
}
