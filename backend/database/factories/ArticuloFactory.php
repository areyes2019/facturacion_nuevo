<?php

namespace Database\Factories;

use App\Enums\ObjetoImpuesto;
use App\Enums\TamanoGoma;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Models\User;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Articulo>
 */
class ArticuloFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // La cadena de precios asume el 0% de descuento y 0% de utilidad por defecto del catálogo
        // del factory (CatalogoFactory), así que costo_con_descuento coincide con precio_proveedor.
        // El precio de venta NO coincide: pasa por el redondeo al peso entero del precio con IVA
        // (ver 024-precios-sin-centavos.md). Si un test asocia el artículo a un catálogo con otro
        // descuento o utilidad debe sobreescribir estos campos explícitamente (ver 011).
        //
        // Con 0% de utilidad distribuidor por defecto (y sin goma) el precio distribuidor cae en la
        // misma cadena que el directo, así que comparten el mismo redondeo (ver
        // 033-precio-distribuidor.md).
        $precio = $this->faker->randomFloat(2, 10, 5000);
        $precioVenta = PrecioArticuloCalculator::redondearAPesoEntero(
            $precio,
            PrecioArticuloCalculator::factorIva(ObjetoImpuesto::SiObjeto),
        );

        return [
            'user_id' => User::factory(),
            'catalogo_id' => Catalogo::factory(),
            'nombre' => $this->faker->unique()->words(3, true),
            'modelo' => $this->faker->bothify('MOD-####'),
            'clave_prod_serv' => '43211503',
            'clave_unidad' => 'H87',
            'objeto_imp' => ObjetoImpuesto::SiObjeto,
            'precio_proveedor' => $precio,
            'utilidad_porcentaje' => null,
            'utilidad_distribuidor_porcentaje' => null,
            'tamano_goma' => null,
            'costo_goma' => 0,
            'costo_con_descuento' => $precio,
            'precio_unitario_sin_iva' => $precioVenta,
            'precio_distribuidor_sin_iva' => $precioVenta,
        ];
    }

    /**
     * Artículo que requiere elaboración de goma (ver 014-costo-elaboracion-goma.md).
     *
     * Deriva `costo_goma` y el precio de venta a partir del costo recibido, bajo el mismo supuesto
     * que `definition()`: catálogo con 0% de descuento y 0% de utilidad. Un test con otro catálogo
     * sobreescribe los campos derivados explícitamente.
     */
    public function conGoma(TamanoGoma $tamano, float $costoGoma): static
    {
        return $this->state(function (array $atributos) use ($tamano, $costoGoma) {
            $costo = PrecioArticuloCalculator::costoTotal((float) $atributos['costo_con_descuento'], $costoGoma);
            $precioCrudo = PrecioArticuloCalculator::precioVentaSinIva($costo, 0.0);

            return [
                'tamano_goma' => $tamano,
                'costo_goma' => $costoGoma,
                'precio_unitario_sin_iva' => PrecioArticuloCalculator::redondearAPesoEntero(
                    $precioCrudo,
                    PrecioArticuloCalculator::factorIva($atributos['objeto_imp'] ?? ObjetoImpuesto::SiObjeto),
                ),
            ];
        });
    }
}
