<?php

namespace App\Models;

use App\Contracts\DocumentoEnviable;
use App\Enums\EstadoCotizacion;
use App\Enums\TipoDescuento;
use App\Mail\CotizacionEnviadaMail;
use Carbon\CarbonInterface;
use Database\Factories\CotizacionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'cliente_id',
    'descuento_cliente_porcentaje',
    'folio',
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
    'factura_id',
])]
class Cotizacion extends Model implements DocumentoEnviable
{
    /** @use HasFactory<CotizacionFactory> */
    use HasFactory;

    protected $table = 'cotizaciones';

    /**
     * Días sin movimiento (`updated_at`) tras los cuales una cotización en borrador o enviada se
     * borra sola (ver 008-cotizaciones.md, "Caducidad automática"). Es la única fuente del plazo:
     * la usan tanto el comando de purga como el `caduca_el` que alimenta el aviso del frontend.
     */
    public const DIAS_SIN_MOVIMIENTO = 30;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function lineas(): HasMany
    {
        return $this->hasMany(CotizacionLinea::class);
    }

    public function pagos(): HasMany
    {
        return $this->hasMany(CotizacionPago::class);
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class);
    }

    /**
     * Suma acumulada de los pagos registrados (anticipo + saldo + pago total, sin distinción de
     * tipo); en cuanto alcanza o supera `total` la cotización pasa a `pagada` (ver
     * 008-cotizaciones.md, supuesto #9).
     */
    public function totalPagado(): float
    {
        return (float) $this->pagos()->sum('monto');
    }

    /** Usa lo que ya venga cargado (`with`/`withCount`) antes de gastar una consulta por fila. */
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
     * Una cotización se puede tirar mientras sea borrador/enviada y no arrastre nada que solo otro
     * flujo sabe deshacer: los pagos tienen movimientos de Tesorería que revierte el endpoint de
     * pagos, y una cotización ya facturada quedó atada a un documento fiscal con vida propia (ver
     * 008-cotizaciones.md, supuestos #32 y #33). Es también la condición exacta de la caducidad
     * automática: lo que no se puede borrar a mano tampoco caduca.
     */
    public function puedeEliminarse(): bool
    {
        return $this->estado->esEditable()
            && $this->factura_id === null
            && ! $this->tienePagos();
    }

    /** Fecha en que el comando de purga la borraría, o null si no caduca. */
    public function caducaEl(): ?CarbonInterface
    {
        if (! $this->puedeEliminarse() || $this->updated_at === null) {
            return null;
        }

        return $this->updated_at->copy()->addDays(self::DIAS_SIN_MOVIMIENTO);
    }

    /**
     * Las que el comando `cotizaciones:purgar-vencidas` debe borrar: mismas condiciones que
     * `puedeEliminarse()`, más el plazo cumplido.
     */
    #[Scope]
    protected function vencidas(Builder $query): void
    {
        $query->whereIn('estado', [EstadoCotizacion::Borrador->value, EstadoCotizacion::Enviada->value])
            ->whereNull('factura_id')
            ->whereDoesntHave('pagos')
            ->where('updated_at', '<=', now()->subDays(self::DIAS_SIN_MOVIMIENTO));
    }

    public function vistaPdf(): string
    {
        return 'pdf.cotizacion';
    }

    /**
     * @return array<string, mixed>
     */
    public function datosPdf(): array
    {
        // `withTrashed` en el artículo: la Clave SAT del PDF sale de ahí y las líneas no guardan
        // copia propia (ver 019-formato-pdf-documentos.md).
        $this->loadMissing(['cliente', 'lineas.articulo' => fn ($relacion) => $relacion->withTrashed()]);

        return ['cotizacion' => $this];
    }

    public function nombreArchivoPdf(): string
    {
        return 'cotizacion-'.$this->folio.'.pdf';
    }

    public function mailable(string $pdf): Mailable
    {
        return new CotizacionEnviadaMail($this, $pdf);
    }

    /**
     * La foto congelada de datos bancarios, con el icono de cada banco ya listo para incrustarse en
     * el HTML del PDF (ver 026-datos-bancarios-cotizacion.md).
     *
     * Resuelve el archivo aquí y no dentro de la plantilla por la misma razón por la que el logo
     * del emisor tiene `logoBase64()`: una vista que lee del disco esconde un acceso a
     * almacenamiento donde nadie lo busca, y hace imposible probar el bloque sin renderizar.
     *
     * Un logo que ya no está en disco deja `logo_base64` en `null` y la plantilla imprime el nombre
     * del banco sin icono: un PDF nunca falla por una imagen.
     *
     * @return array<int, array<string, string|null>>
     */
    public function datosBancariosParaPdf(): array
    {
        return array_map(function (array $banco) {
            $contenido = DatoBancario::contenidoLogoDe($banco['logo_ruta'] ?? null);

            if ($contenido === null && filled($banco['logo_ruta'] ?? null)) {
                // Hay logo capturado pero el archivo ya no está. Es distinto de "no hay logo" y no
                // puede pasar en silencio: el usuario vería el hueco sin saber por qué.
                Log::warning('Falta en disco el logo de un banco.', ['ruta' => $banco['logo_ruta']]);
            }

            $banco['logo_base64'] = $contenido === null
                ? null
                : 'data:image/webp;base64,'.base64_encode($contenido);

            return $banco;
        }, $this->datos_bancarios ?? []);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoCotizacion::class,
            'descuento_cliente_porcentaje' => 'decimal:2',
            'descuento_global_tipo' => TipoDescuento::class,
            'descuento_global_valor' => 'decimal:2',
            'subtotal' => 'decimal:2',
            'total_descuento' => 'decimal:2',
            'total_iva_16' => 'decimal:2',
            'total_iva_0' => 'decimal:2',
            'total_exento' => 'decimal:2',
            'ajuste_al_peso' => 'decimal:2',
            'total' => 'decimal:2',
            // Foto de los datos bancarios con los que salió esta cotización, tomada al crearla
            // (ver 026-datos-bancarios-cotizacion.md). Fuera de #[Fillable] a propósito: no es un
            // dato que el cliente mande en el POST, lo pone el controlador.
            'datos_bancarios' => 'array',
        ];
    }
}
