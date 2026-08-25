<?php

namespace App\Enums;

/**
 * Tarifa fija de envío a domicilio, elegida a mano (ver 038-produccion-ordenes-trabajo.md). Mismo
 * mecanismo que `TamanoGoma` (014): cada caso es dueño de su propia clave de configuración.
 */
enum TarifaEnvio: string
{
    case A = 'a';
    case B = 'b';
    case C = 'c';

    public function texto(): string
    {
        return match ($this) {
            self::A => 'Tarifa A',
            self::B => 'Tarifa B',
            self::C => 'Tarifa C',
        };
    }

    public function claveConfiguracion(): ClaveConfiguracion
    {
        return match ($this) {
            self::A => ClaveConfiguracion::EnvioTarifaA,
            self::B => ClaveConfiguracion::EnvioTarifaB,
            self::C => ClaveConfiguracion::EnvioTarifaC,
        };
    }
}
