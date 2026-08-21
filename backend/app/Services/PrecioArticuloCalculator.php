<?php

namespace App\Services;

use App\Enums\ObjetoImpuesto;
use App\Models\Articulo;
use App\Models\Catalogo;

/**
 * Cadena de cálculo de precios de un artículo (ver 011-precio-proveedor-utilidad.md,
 * 014-costo-elaboracion-goma.md, 024-precios-sin-centavos.md y 033-precio-distribuidor.md).
 *
 *   precio_proveedor           (capturado)               $120.00
 *     ↓ × (1 − descuento / 100)                          descuento del catálogo
 *   costo_con_descuento        (calculado, persistido)   $120.00   ← costo del aparato
 *     ├─ + costo_goma ────────────────────────┐          goma (solo entra al directo)
 *     │  costo_total (al leer)   $130.00       │
 *     │    ↓ × (1 + utilidad_efectiva/100)     │  ↓ × (1 + utilidad_distribuidor_efectiva/100)
 *     │      → techo2                          │      → techo2 (SIN goma)
 *     │  precio_venta_crudo_sin_iva  $201.50    │  precio_distribuidor_venta_crudo_sin_iva
 *     │    ↓ redondeo al peso entero (024)      │    ↓ redondeo al peso entero (024)
 *     │  precio_unitario_sin_iva     $201.72    │  precio_distribuidor_sin_iva
 *     └─────────────────────────────────────────┘
 *
 * El porcentaje se interpreta como markup sobre el costo: un 25% significa "quiero ganar el 25% de
 * lo que me costó", de ahí la multiplicación.
 *
 * El costo de goma entra DESPUÉS del descuento (el descuento es un beneficio de compra sobre lo que
 * le pagas al proveedor; la goma la elaboras tú) y ANTES del markup (el precio de venta siempre es
 * calculado, así que un costo que no entrara al markup solo produciría un precio insuficiente).
 * Un artículo sin goma tiene costo_goma = 0 y la cadena queda idéntica a la de 011.
 *
 * El precio distribuidor (033) parte de `costo_con_descuento`, NUNCA de `costo_total`: el
 * distribuidor no paga ni absorbe el costo de la goma. Comparte con el directo las mismas funciones
 * de redondeo (`techo2`, `redondearAPesoEntero`) y el mismo factor de IVA por `objeto_imp`; lo único
 * que cambia es el costo base y el porcentaje de utilidad.
 *
 * El redondeo al peso entero es el ÚLTIMO eslabón y vive dentro de `calcularCadena`, no en cada
 * llamador: así lo heredan sin lógica propia el alta, la edición, la importación CSV, el recálculo
 * en bloque del catálogo (011) y el mantenimiento masivo (021).
 *
 * Los casos frontera de esta cadena viven en shared/fixtures/precios-articulos.json, compartidos
 * con la implementación espejo en TypeScript (frontend/src/lib/precioArticulo.ts).
 */
class PrecioArticuloCalculator
{
    /**
     * Redondeo matemático estándar a 2 decimales (igual que en 009).
     */
    public static function redondeo2(float $valor): float
    {
        return round($valor, 2);
    }

    /**
     * Techo a 2 decimales, redondeando DESPUÉS de escalar a centavos.
     *
     * El orden es la parte sustancial de la definición y no es intercambiable:
     *
     *   - `ceil($valor * 100) / 100` falla porque el producto costo × (1 + % / 100) arrastra error
     *     de representación en punto flotante.
     *   - `ceil(round($valor, 4) * 100) / 100` tampoco sirve: corrige el valor pero vuelve a
     *     introducir error en la multiplicación por 100 (0.07 * 100 = 7.000000000000001), y termina
     *     cobrando un centavo de más. Con costo $15.40 y 5% el resultado exacto es $16.17 y esa
     *     variante devuelve $16.18.
     *   - Redondear a 6 decimales sobre el valor ya expresado en centavos elimina ambas fuentes de
     *     error. Verificado contra aritmética entera de centavos sobre 4.2 millones de
     *     combinaciones, sin desviaciones (ver 011).
     */
    public static function techo2(float $valor): float
    {
        return ceil(round($valor * 100, 6)) / 100;
    }

    /**
     * Costo con descuento = redondeo2(precio_proveedor × (1 − descuento / 100)).
     */
    public static function costoConDescuento(float $precioProveedor, float $descuento): float
    {
        return self::redondeo2($precioProveedor * (1 - $descuento / 100));
    }

    /**
     * Precio de proveedor tras un aumento porcentual = redondeo2(precio_proveedor × (1 + aumento / 100)).
     *
     * Es el único eslabón capturado a mano de toda la cadena, y por eso es el que mueve el aumento
     * masivo por catálogo (ver 021-mantenimiento-articulos-catalogos.md): subir directamente el
     * `costo_con_descuento` dejaría el precio de proveedor guardado mintiendo sobre la lista real, y
     * el primer recálculo —editar el artículo, o cambiar el descuento del catálogo— devolvería el
     * costo viejo sin que nadie se entere.
     *
     * Redondeo matemático a centavos, el mismo de `costoConDescuento`: un aumento cuyo efecto no
     * llega a medio centavo deja el precio igual, y dos aumentos encadenados no equivalen a su suma.
     */
    public static function precioProveedorAumentado(float $precioProveedor, float $aumentoPorcentaje): float
    {
        return self::redondeo2($precioProveedor * (1 + $aumentoPorcentaje / 100));
    }

    /**
     * Costo total = redondeo2(costo_con_descuento + costo_goma).
     *
     * Los dos sumandos ya vienen con 2 decimales, así que el redondeo no cambia ningún valor de
     * negocio; está para que la suma en punto flotante no arrastre una cola de error hacia el
     * techo2 del markup, que es sensible al último centavo.
     */
    public static function costoTotal(float $costoConDescuento, float $costoGoma): float
    {
        return self::redondeo2($costoConDescuento + $costoGoma);
    }

    /**
     * Precio de venta crudo sin IVA = techo2(costo_total × (1 + utilidad_efectiva / 100)).
     *
     * Es el precio que produce el markup, antes del redondeo al peso entero de 024. No se persiste:
     * la columna guarda el valor que sale de `redondearAPesoEntero`.
     */
    public static function precioVentaSinIva(float $costoTotal, float $utilidadPorcentaje): float
    {
        return self::techo2($costoTotal * (1 + $utilidadPorcentaje / 100));
    }

    /**
     * Factor por el que se multiplica el precio sin IVA para obtener el precio que ve el cliente
     * (ver 024-precios-sin-centavos.md).
     *
     * Solo `SiObjeto` (02) causa un 16% que el cliente vea sumarse encima. En `NoObjeto` (01),
     * `SiObjetoNoDesglose` (03) y `SiObjetoNoCausaImpuesto` (04) el número que él lee es el precio a
     * secas, así que es ése el que debe quedar entero y el factor es 1.
     */
    public static function factorIva(?ObjetoImpuesto $objetoImp): float
    {
        return $objetoImp === ObjetoImpuesto::SiObjeto ? 1.16 : 1.0;
    }

    /**
     * Ajusta el precio sin IVA para que el precio con IVA caiga en un peso entero, siempre hacia
     * arriba (ver 024-precios-sin-centavos.md).
     *
     * Un centavo del precio sin IVA se convierte en 1.16 centavos del precio con IVA, más ancho que
     * la ventana de un centavo del redondeo. De ahí las dos propiedades que gobiernan esta función:
     *
     *   - Para un objetivo dado existe **a lo más un** centavo válido, y está a menos de medio paso
     *     del cociente `objetivo ÷ factor`, así que basta probar `floor` y `floor + 0.01`.
     *   - Hay objetivos que **ningún** centavo alcanza (el 13.8% de los enteros: $7, $12, $17, $22,
     *     $36...), y por eso el ciclo sube al siguiente peso. Nunca hay dos inalcanzables
     *     consecutivos —verificado del $1 al $100,000—, de modo que un solo incremento basta
     *     siempre y el ciclo termina.
     *
     * Con factor 1.0 la regla se degrada a un techo al peso: el objetivo siempre es alcanzable.
     */
    public static function redondearAPesoEntero(float $precioCrudoSinIva, float $factorIva): float
    {
        if ($precioCrudoSinIva <= 0.0) {
            return 0.0;
        }

        $objetivo = ceil(round($precioCrudoSinIva * $factorIva, 6));

        while (true) {
            $base = floor(round($objetivo / $factorIva * 100, 6)) / 100;

            foreach ([$base, self::redondeo2($base + 0.01)] as $candidato) {
                if (self::redondeo2($candidato * $factorIva) === (float) $objetivo) {
                    return $candidato;
                }
            }

            $objetivo++;
        }
    }

    /**
     * Utilidad en pesos = precio_unitario_sin_iva − costo_total (siempre sin IVA).
     *
     * Se mide contra el costo total, no contra el del aparato: es lo que impide que un artículo con
     * goma muestre una utilidad que ignora ese costo.
     */
    public static function utilidad(float $precioVentaSinIva, float $costoTotal): float
    {
        return round($precioVentaSinIva - $costoTotal, 2);
    }

    /**
     * Porcentaje de utilidad efectivo de un artículo: el propio si lo tiene, si no el del catálogo.
     */
    public static function utilidadEfectiva(Articulo $articulo, Catalogo $catalogo): float
    {
        return (float) ($articulo->utilidad_porcentaje ?? $catalogo->utilidad_porcentaje);
    }

    /**
     * Porcentaje de utilidad distribuidor efectivo: el propio del artículo si lo tiene, si no el del
     * catálogo (ver 033-precio-distribuidor.md). Misma herencia viva que `utilidadEfectiva`, sobre su
     * propio par de columnas.
     */
    public static function utilidadDistribuidorEfectiva(Articulo $articulo, Catalogo $catalogo): float
    {
        return (float) ($articulo->utilidad_distribuidor_porcentaje ?? $catalogo->utilidad_distribuidor_porcentaje);
    }

    /**
     * Calcula y devuelve los valores derivados de la cadena, para el precio directo y el distribuidor
     * (033-precio-distribuidor.md).
     *
     * `costo_total` viaja en el resultado por comodidad de quien calcula, pero NO se persiste: es la
     * suma de dos columnas, mismo criterio por el que tampoco se persiste la utilidad.
     *
     * `$utilidadDistribuidorPorcentaje` va al final con default `0.0` (y no junto a
     * `$utilidadPorcentaje`) para no correr el orden posicional de `$costoGoma` y `$objetoImp`:
     * migraciones ya aplicadas llaman a este método con solo tres argumentos, apoyadas en esos
     * defaults, y deben seguir funcionando sin tocarlas.
     *
     * @return array{costo_con_descuento: float, costo_total: float, precio_unitario_sin_iva: float, precio_distribuidor_sin_iva: float}
     */
    public static function calcularCadena(
        float $precioProveedor,
        float $descuento,
        float $utilidadPorcentaje,
        float $costoGoma = 0.0,
        ?ObjetoImpuesto $objetoImp = null,
        float $utilidadDistribuidorPorcentaje = 0.0,
    ): array {
        $costo = self::costoConDescuento($precioProveedor, $descuento);
        $costoTotal = self::costoTotal($costo, $costoGoma);
        $precioCrudo = self::precioVentaSinIva($costoTotal, $utilidadPorcentaje);
        $precioVenta = self::redondearAPesoEntero($precioCrudo, self::factorIva($objetoImp));

        // El distribuidor parte de `$costo` (sin goma), nunca de `$costoTotal`.
        $precioDistribuidorCrudo = self::precioVentaSinIva($costo, $utilidadDistribuidorPorcentaje);
        $precioDistribuidor = self::redondearAPesoEntero($precioDistribuidorCrudo, self::factorIva($objetoImp));

        return [
            'costo_con_descuento' => $costo,
            'costo_total' => $costoTotal,
            'precio_unitario_sin_iva' => $precioVenta,
            'precio_distribuidor_sin_iva' => $precioDistribuidor,
        ];
    }
}
