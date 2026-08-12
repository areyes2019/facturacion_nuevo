<?php

namespace App\Enums;

/**
 * Por qué se movió el inventario (ver 017-inventario.md).
 *
 * Se divide en dos grupos que no se mezclan: los **automáticos**, que solo puede escribir el propio
 * sistema al recibir una orden, timbrar, entregar o cancelar; y los **manuales**, únicos aceptados
 * en el endpoint de ajuste. Enviar un motivo automático en un ajuste se rechaza con 422: no se
 * puede falsificar el origen de un movimiento.
 */
enum MotivoMovimientoInventario: string
{
    // Automáticos.
    case RecepcionOrden = 'recepcion_orden';
    case VentaFactura = 'venta_factura';
    case VentaCotizacion = 'venta_cotizacion';
    case CancelacionFactura = 'cancelacion_factura';

    // Manuales.
    case ConteoFisico = 'conteo_fisico';
    case Merma = 'merma';
    case Devolucion = 'devolucion';
    case EntradaInicial = 'entrada_inicial';
    case Otro = 'otro';

    public function texto(): string
    {
        return match ($this) {
            self::RecepcionOrden => 'Recepción de orden de compra',
            self::VentaFactura => 'Venta facturada',
            self::VentaCotizacion => 'Entrega de cotización',
            self::CancelacionFactura => 'Cancelación de factura',
            self::ConteoFisico => 'Conteo físico',
            self::Merma => 'Merma o daño',
            self::Devolucion => 'Devolución',
            self::EntradaInicial => 'Entrada inicial',
            self::Otro => 'Otro',
        };
    }

    public function esManual(): bool
    {
        return in_array($this, self::manuales(), true);
    }

    /**
     * @return array<int, self>
     */
    public static function manuales(): array
    {
        return [
            self::ConteoFisico,
            self::Merma,
            self::Devolucion,
            self::EntradaInicial,
            self::Otro,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function valoresManuales(): array
    {
        return array_map(fn (self $caso) => $caso->value, self::manuales());
    }

    /**
     * Opciones que el frontend ofrece en el diálogo de ajuste: solo las manuales.
     *
     * @return array<int, array{id: string, texto: string}>
     */
    public static function opcionesManuales(): array
    {
        return array_map(
            fn (self $caso) => ['id' => $caso->value, 'texto' => $caso->texto()],
            self::manuales(),
        );
    }
}
