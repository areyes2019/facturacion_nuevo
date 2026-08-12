<?php

namespace App\Console\Commands;

use App\Models\Cotizacion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cotizaciones:purgar-vencidas')]
#[Description('Elimina las cotizaciones en borrador o enviada que llevan 30 días sin movimiento (ver 008-cotizaciones.md).')]
class PurgarCotizacionesVencidas extends Command
{
    /**
     * Borrado físico: las líneas se van por FK en cascada. No toca las cotizaciones con pagos
     * registrados ni las ya facturadas — eso lo decide el scope `vencidas`, que replica la misma
     * regla del borrado manual.
     */
    public function handle(): int
    {
        $borradas = Cotizacion::query()->vencidas()->delete();

        $this->info($borradas === 0
            ? 'No hay cotizaciones vencidas que borrar.'
            : "Se eliminaron {$borradas} cotizaciones sin movimiento en los últimos ".Cotizacion::DIAS_SIN_MOVIMIENTO.' días.');

        return self::SUCCESS;
    }
}
