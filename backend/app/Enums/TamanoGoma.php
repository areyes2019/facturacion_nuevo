<?php

namespace App\Enums;

/**
 * Categoría de tamaño de la goma que se elabora para un sello (ver 014-costo-elaboracion-goma.md).
 *
 * Cuatro valores fijos definidos aquí, no administrables por el usuario. La ausencia de goma se
 * representa con NULL en `articulos.tamano_goma`, no con un caso más: es el mismo criterio con el
 * que `utilidad_porcentaje` nulo significa "no aplica lo propio" en 011.
 *
 * Las medidas son orientativas y viven en el frontend como texto de ayuda: el sistema no captura
 * dimensiones ni deduce la categoría de ninguna medida.
 */
enum TamanoGoma: string
{
    case Chica = 'chica';
    case Mediana = 'mediana';
    case Grande = 'grande';
    case Jumbo = 'jumbo';

    /**
     * Clave de configuración que guarda el costo de esta categoría.
     *
     * La correspondencia vive aquí y solo aquí: el artículo guarda el tamaño, nunca el nombre de la
     * clave, de modo que renombrar un ajuste no deja artículos apuntando a un renglón inexistente.
     */
    public function claveConfiguracion(): ClaveConfiguracion
    {
        return match ($this) {
            self::Chica => ClaveConfiguracion::CostoGomaChica,
            self::Mediana => ClaveConfiguracion::CostoGomaMediana,
            self::Grande => ClaveConfiguracion::CostoGomaGrande,
            self::Jumbo => ClaveConfiguracion::CostoGomaJumbo,
        };
    }

    public function texto(): string
    {
        return match ($this) {
            self::Chica => 'Chica',
            self::Mediana => 'Mediana',
            self::Grande => 'Grande',
            self::Jumbo => 'Jumbo',
        };
    }

    /**
     * Categoría cuyo costo cambia al tocar una clave de configuración dada, o null si esa clave no
     * es un costo de goma. Alimenta el recálculo en bloque y el conteo de impacto.
     */
    public static function porClaveConfiguracion(ClaveConfiguracion $clave): ?self
    {
        foreach (self::cases() as $caso) {
            if ($caso->claveConfiguracion() === $clave) {
                return $caso;
            }
        }

        return null;
    }
}
