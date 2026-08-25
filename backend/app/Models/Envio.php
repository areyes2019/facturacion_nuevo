<?php

namespace App\Models;

use App\Enums\FormaPagoEnvio;
use App\Enums\TarifaEnvio;
use Database\Factories\EnvioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Envío a domicilio de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 *
 * 1 a 1 con `OrdenTrabajo`. No se edita ni se borra una vez creado. `monto` es la copia congelada
 * del valor configurado de la tarifa al momento de crearse — mismo criterio que `Articulo::costo_goma`
 * (014): un cambio posterior en Configuración no debe mover envíos ya hechos.
 */
#[Fillable([
    'orden_trabajo_id',
    'nombre_receptor',
    'telefono_receptor',
    'fecha_recepcion',
    'hora_recepcion',
    'tarifa',
    'monto',
    'forma_pago',
])]
class Envio extends Model
{
    /** @use HasFactory<EnvioFactory> */
    use HasFactory;

    protected $table = 'envios';

    public function ordenTrabajo(): BelongsTo
    {
        return $this->belongsTo(OrdenTrabajo::class);
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
        return 'Envío de Orden '.$this->ordenTrabajo->folioFormateado();
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
        ];
    }
}
