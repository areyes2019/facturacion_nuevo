<?php

namespace App\Enums;

/**
 * Catálogo SAT c_MotivoCancelacion (CFDI 4.0). Solo 4 valores fijos y estables, por lo que se
 * hardcodea como enum en vez de agregarse a la base sqlite de catálogos SAT (ver
 * 007-facturacion.md, adición técnica C).
 */
enum MotivoCancelacion: string
{
    case ComprobanteConErroresConRelacion = '01';
    case ComprobanteConErroresSinRelacion = '02';
    case OperacionNoLlevada = '03';
    case OperacionNominativaEnGlobal = '04';

    public function texto(): string
    {
        return match ($this) {
            self::ComprobanteConErroresConRelacion => 'Comprobante emitido con errores con relación',
            self::ComprobanteConErroresSinRelacion => 'Comprobante emitido con errores sin relación',
            self::OperacionNoLlevada => 'No se llevó a cabo la operación',
            self::OperacionNominativaEnGlobal => 'Operación nominativa relacionada en una factura global',
        };
    }

    /**
     * El SAT exige un folio fiscal sustituto cuando el motivo es "01" (ver
     * 007-facturacion.md, adición técnica H).
     */
    public function requiereFacturaSustituta(): bool
    {
        return $this === self::ComprobanteConErroresConRelacion;
    }

    /**
     * @return array<int, array{id: string, texto: string}>
     */
    public static function opciones(): array
    {
        return array_map(
            fn (self $caso) => ['id' => $caso->value, 'texto' => $caso->texto()],
            self::cases(),
        );
    }
}
