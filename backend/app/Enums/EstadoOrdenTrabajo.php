<?php

namespace App\Enums;

/**
 * Máquina de estados de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md).
 *
 * Cinco casos, secuenciales, sin retroceso. `ADomicilio` solo se alcanza creando un `Envio` (nunca
 * a mano) y `Entregado` es terminal, alcanzable desde `ADomicilio` (repartidor confirma) o desde el
 * QR del documento origen (entrega en mostrador).
 */
enum EstadoOrdenTrabajo: string
{
    case Pendiente = 'pendiente';
    case EnProduccion = 'en_produccion';
    case ListoParaEntregar = 'listo_para_entregar';
    case ADomicilio = 'a_domicilio';
    case Entregado = 'entregado';

    public function texto(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnProduccion => 'En producción',
            self::ListoParaEntregar => 'Listo para entregar',
            self::ADomicilio => 'A domicilio',
            self::Entregado => 'Entregado',
        };
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
