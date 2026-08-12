<?php

namespace App\Enums;

/**
 * Claves admitidas del almacén de configuración global (ver 014-costo-elaboracion-goma.md).
 *
 * El almacén es clave→valor para que los ajustes futuros (tasa de IVA, datos fiscales del emisor,
 * folio inicial) entren sin tabla nueva, pero la lista de claves es cerrada: una clave fuera de este
 * enum se rechaza con 422 y no crea fila. El pizarrón no se llena de renglones que nadie lee.
 *
 * Cada caso es dueño de su valor por defecto y de sus reglas de validación, porque el valor se
 * persiste como texto y el tipo no lo impone la columna.
 */
enum ClaveConfiguracion: string
{
    case CostoGomaChica = 'costo_goma_chica';
    case CostoGomaMediana = 'costo_goma_mediana';
    case CostoGomaGrande = 'costo_goma_grande';

    /**
     * Valor con el que arranca un usuario que nunca ha guardado la pantalla de Configuración.
     *
     * Cumple tres papeles y por eso no hace falta un cuarto mecanismo: es el valor de fábrica, es
     * lo que devuelve la lectura cuando no hay fila, y es la red si una fila se borra a mano.
     */
    public function valorPorDefecto(): string
    {
        return match ($this) {
            self::CostoGomaChica => '6.00',
            self::CostoGomaMediana => '10.00',
            self::CostoGomaGrande => '20.00',
        };
    }

    /**
     * Reglas de validación del valor de esta clave.
     *
     * Se permite 0.00 (una categoría que no cuesta nada); no se aceptan negativos, porque una goma
     * que abarate el artículo no existe.
     *
     * @return array<int, string>
     */
    public function reglas(): array
    {
        return match ($this) {
            self::CostoGomaChica,
            self::CostoGomaMediana,
            self::CostoGomaGrande => ['numeric', 'gte:0', 'decimal:0,2'],
        };
    }

    /**
     * Valores de fábrica de todas las claves, indexados por clave.
     *
     * @return array<string, string>
     */
    public static function valoresPorDefecto(): array
    {
        $valores = [];

        foreach (self::cases() as $caso) {
            $valores[$caso->value] = $caso->valorPorDefecto();
        }

        return $valores;
    }
}
