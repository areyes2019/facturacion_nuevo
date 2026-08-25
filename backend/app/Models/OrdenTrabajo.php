<?php

namespace App\Models;

use App\Enums\EstadoOrdenTrabajo;
use Database\Factories\OrdenTrabajoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 *
 * Cuelga de un `Pedido` o de una `Cotizacion` con al menos un pago (`documentable`, relación
 * polimórfica igual que `Movimiento::documentable` de 010-tesoreria). No guarda cliente, producto ni
 * precios propios: se leen del documento origen, nunca se duplican.
 *
 * `imagen_ruta` vive fuera de `#[Fillable]` por el mismo criterio que `Articulo::imagen_ruta` (020):
 * solo la escribe `ImagenOrdenTrabajoService`.
 */
#[Fillable([
    'folio',
    'estado',
    'observaciones',
    'documentable_type',
    'documentable_id',
])]
class OrdenTrabajo extends Model
{
    /** @use HasFactory<OrdenTrabajoFactory> */
    use HasFactory;

    protected $table = 'orden_trabajos';

    /** Directorio de las imágenes dentro del disco privado (mismo criterio que 020). */
    public const DIRECTORIO_IMAGENES = 'orden_trabajos';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function envio(): MorphOne
    {
        return $this->morphOne(Envio::class, 'documentable');
    }

    public function folioFormateado(): string
    {
        return 'OT-'.str_pad((string) $this->folio, 5, '0', STR_PAD_LEFT);
    }

    /** Alias corto del tipo de documento origen, el mismo que acepta `StoreOrdenTrabajoRequest`. */
    public function documentableAlias(): string
    {
        return $this->documentable instanceof Pedido ? 'pedido' : 'cotizacion';
    }

    /** Etiqueta del documento origen tal como ya se muestra en Pedidos/Cotizaciones. */
    public function documentoEtiqueta(): string
    {
        return $this->documentable instanceof Pedido
            ? $this->documentable->folioFormateado()
            : 'Cotización '.$this->documentable->folio;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'estado' => EstadoOrdenTrabajo::class,
        ];
    }
}
