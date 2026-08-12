<?php

namespace App\Http\Controllers;

use App\Enums\ClaveConfiguracion;
use App\Enums\TamanoGoma;
use App\Models\Articulo;
use App\Services\ConfiguracionService;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Pizarrón de ajustes globales del usuario (ver 014-costo-elaboracion-goma.md).
 *
 * Hoy solo guarda los tres costos de elaboración de goma, pero el almacén es clave→valor para que
 * la tasa de IVA, los datos fiscales del emisor y el folio inicial entren sin tabla nueva.
 */
class ConfiguracionController extends Controller
{
    public function __construct(private readonly ConfiguracionService $configuracion) {}

    /**
     * Todos los ajustes con su valor efectivo. Nunca responde incompleto: una clave sin fila
     * devuelve su valor de fábrica, así que el frontend no tiene que saber qué es un default.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->configuracion->todos($request->user()));
    }

    /**
     * Guarda una o más claves. Las ausentes se dejan como están; una clave desconocida devuelve 422
     * y no crea fila.
     *
     * Si cambia algún costo de goma, recalcula en bloque los artículos de esa categoría dentro de
     * una transacción: o se actualizan todos, o ninguno.
     */
    public function update(Request $request): JsonResponse
    {
        $valores = $this->validarClaves($request);
        $user = $request->user();
        $vigentes = $this->configuracion->todos($user);

        DB::transaction(function () use ($user, $valores, $vigentes) {
            $this->configuracion->guardar($user, $valores);

            foreach ($valores as $clave => $valor) {
                $tamano = TamanoGoma::porClaveConfiguracion(ClaveConfiguracion::from($clave));

                if ($tamano === null || (float) $valor === (float) $vigentes[$clave]) {
                    continue;
                }

                $this->recalcularArticulosDe($user->id, $tamano, (float) $valor);
            }
        });

        return response()->json($this->configuracion->todos($user));
    }

    /**
     * Conteo exacto de artículos cuyo precio de venta cambiaría con los valores propuestos, sin
     * persistir nada. Alimenta el diálogo de confirmación del frontend.
     *
     * Un costo que se envía sin cambio aporta cero, y por eso el conteo no es "cuántos artículos
     * tienen goma" sino "a cuántos se les movería el precio".
     */
    public function impactoPrecios(Request $request): JsonResponse
    {
        $propuestos = $this->validarClaves($request);
        $user = $request->user();
        $vigentes = $this->configuracion->todos($user);

        $afectados = 0;

        foreach ($propuestos as $clave => $valor) {
            $tamano = TamanoGoma::porClaveConfiguracion(ClaveConfiguracion::from($clave));

            if ($tamano === null || (float) $valor === (float) $vigentes[$clave]) {
                continue;
            }

            $afectados += $this->articulosDe($user->id, $tamano)
                ->with('catalogo')
                ->get()
                ->filter(function (Articulo $articulo) use ($valor) {
                    $precio = PrecioArticuloCalculator::precioVentaSinIva(
                        PrecioArticuloCalculator::costoTotal((float) $articulo->costo_con_descuento, (float) $valor),
                        $this->utilidadEfectivaDe($articulo),
                    );

                    return $precio !== (float) $articulo->precio_unitario_sin_iva;
                })
                ->count();
        }

        return response()->json(['articulos_afectados' => $afectados]);
    }

    /**
     * Valida las claves recibidas contra la lista cerrada del enum, cada una con sus propias
     * reglas. El almacén guarda texto, así que el tipo lo impone quien escribe.
     *
     * @return array<string, string>
     */
    private function validarClaves(Request $request): array
    {
        $entrada = $request->all();
        $reglas = [];

        foreach (array_keys($entrada) as $clave) {
            $caso = ClaveConfiguracion::tryFrom((string) $clave);

            abort_if($caso === null, 422, "Ajuste desconocido: {$clave}");

            $reglas[$clave] = ['required', ...$caso->reglas()];
        }

        return Validator::make($entrada, $reglas)->validate();
    }

    /**
     * Actualiza la copia congelada del costo de goma y el precio de venta de los artículos de una
     * categoría. Recorre en PHP con PrecioArticuloCalculator, no con un UPDATE masivo: el techo a 2
     * decimales no es portable entre MySQL y SQLite.
     */
    private function recalcularArticulosDe(int $userId, TamanoGoma $tamano, float $costoGoma): void
    {
        foreach ($this->articulosDe($userId, $tamano)->with('catalogo')->get() as $articulo) {
            $articulo->update([
                'costo_goma' => $costoGoma,
                'precio_unitario_sin_iva' => PrecioArticuloCalculator::precioVentaSinIva(
                    PrecioArticuloCalculator::costoTotal((float) $articulo->costo_con_descuento, $costoGoma),
                    $this->utilidadEfectivaDe($articulo),
                ),
            ]);
        }
    }

    /**
     * @return Builder<Articulo>
     */
    private function articulosDe(int $userId, TamanoGoma $tamano)
    {
        return Articulo::query()
            ->where('user_id', $userId)
            ->where('tamano_goma', $tamano->value);
    }

    private function utilidadEfectivaDe(Articulo $articulo): float
    {
        return (float) ($articulo->utilidad_porcentaje ?? $articulo->catalogo->utilidad_porcentaje);
    }
}
