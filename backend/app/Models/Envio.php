<?php

namespace App\Models;

use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use Database\Factories\EnvioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Envío a domicilio (ver 038-produccion-ordenes-trabajo.md y
 * 041-envio-domicilio-direccion-y-distribuidor.md).
 *
 * Cuelga de un `OrdenTrabajo` (Producción) o directamente de una `Cotizacion` de cliente
 * distribuidor (`documentable`, misma técnica que `OrdenTrabajo::documentable` y
 * `Movimiento::documentable` de 010). No se edita ni se borra una vez creado — la única transición
 * permitida es `entregado_en` (041), y solo cuando cuelga directo de una `Cotizacion`.
 *
 * `monto` es la copia congelada del valor configurado de la tarifa al momento de crearse — mismo
 * criterio que `Articulo::costo_goma` (014): un cambio posterior en Configuración no debe mover
 * envíos ya hechos.
 */
#[Fillable([
    'documentable_type',
    'documentable_id',
    'nombre_receptor',
    'telefono_receptor',
    'direccion',
    'fecha_recepcion',
    'hora_recepcion',
    'tarifa',
    'monto',
    'forma_pago',
    'entregado_en',
])]
class Envio extends Model
{
    /** @use HasFactory<EnvioFactory> */
    use HasFactory;

    protected $table = 'envios';

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Movimiento de ingreso que este envío generó en Tesorería, solo cuando es `prepagado`. Un
     * envío `por_cobrar` nunca tiene movimiento: ese dinero no pasa por el negocio.
     */
    public function movimiento(): MorphOne
    {
        return $this->morphOne(Movimiento::class, 'documentable');
    }

    public function conceptoMovimiento(): string
    {
        return match (true) {
            $this->documentable instanceof OrdenTrabajo => 'Envío de Orden '.$this->documentable->folioFormateado(),
            $this->documentable instanceof Cotizacion => 'Envío de Cotización '.$this->documentable->folio,
        };
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'date',
            'tarifa' => TarifaEnvio::class,
            'monto' => 'decimal:2',
            'forma_pago' => FormaPagoEnvio::class,
            'entregado_en' => 'datetime',
        ];
    }
}
