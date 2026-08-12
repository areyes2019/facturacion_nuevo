<?php

namespace App\Http\Controllers;

use App\Enums\EstadoOrdenCompra;
use App\Enums\MotivoMovimientoInventario;
use App\Enums\TasaIva;
use App\Http\Requests\Inventario\ActualizarParametrosInventarioRequest;
use App\Http\Requests\Inventario\AjustarInventarioRequest;
use App\Http\Resources\InventarioResource;
use App\Http\Resources\MovimientoInventarioResource;
use App\Http\Resources\OrdenCompraResource;
use App\Models\Articulo;
use App\Models\OrdenCompra;
use App\Services\FacturaTotalesCalculator;
use App\Services\InventarioService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla de Existencias (ver 017-inventario.md).
 *
 * Los umbrales se escriben aquí directamente porque no mueven piezas; todo lo que sí las mueve pasa
 * por `InventarioService`, que es el único que toca `existencia` y `faltante_pendiente`.
 */
class InventarioController extends Controller
{
    /**
     * Expresión del costo total por unidad. Ni el costo total ni la utilidad están persistidos, así
     * que ordenar y sumar por ellos se traduce a la aritmética que los define — mismo camino que ya
     * tomaron 011 y 014.
     */
    private const COSTO_TOTAL = '(costo_con_descuento + costo_goma)';

    private const UTILIDAD = '(precio_unitario_sin_iva - costo_con_descuento - costo_goma)';

    /** Columnas ordenables del listado. */
    private const ORDENACIONES = [
        'nombre' => 'nombre',
        'modelo' => 'modelo',
        'existencia' => 'existencia',
        'faltante' => 'faltante_pendiente',
        'invertido' => 'existencia * '.self::COSTO_TOTAL,
        'beneficio' => 'existencia * '.self::UTILIDAD,
    ];

    public function __construct(private readonly InventarioService $inventario) {}

    /**
     * Listado paginado con los cuatro totales del conjunto **filtrado completo**.
     *
     * Los totales no se suman sobre los 15 registros de la página: si lo hicieran, el "dinero
     * invertido" cambiaría al pasar de página y sería un número bonito y falso. Son una consulta
     * agregada aparte, resuelta en la base de datos, que viaja en `meta.totales`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtrada = $this->filtrar($request);

        $articulos = $this->ordenar(clone $filtrada, $request)
            ->with('catalogo.proveedor')
            ->paginate(15)
            ->withQueryString();

        return InventarioResource::collection($articulos)
            ->additional(['meta' => ['totales' => $this->totales(clone $filtrada)]]);
    }

    /**
     * Umbrales de reposición. No genera movimiento: cambiar un umbral no mueve piezas.
     */
    public function parametros(ActualizarParametrosInventarioRequest $request, Articulo $articulo): InventarioResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $articulo->forceFill([
            'minimo' => (int) $request->validated('minimo'),
            'maximo' => $request->validated('maximo'),
        ])->save();

        return new InventarioResource($articulo->fresh(['catalogo.proveedor']));
    }

    /**
     * Ajuste manual: fija la cantidad final y pone el faltante en cero.
     *
     * Meter un artículo al inventario por primera vez usa este mismo endpoint; no hay un alta
     * aparte, porque declarar "tengo 10" es la misma operación se venga de 0 o de 7.
     */
    public function ajuste(AjustarInventarioRequest $request, Articulo $articulo): InventarioResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $this->inventario->ajuste(
            $articulo,
            (int) $request->validated('cantidad'),
            MotivoMovimientoInventario::from($request->validated('motivo')),
            $request->validated('nota'),
        );

        return new InventarioResource($articulo->fresh(['catalogo.proveedor']));
    }

    /**
     * Historial del artículo, más reciente primero. Solo lectura.
     */
    public function movimientos(Request $request, Articulo $articulo): AnonymousResourceCollection
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $movimientos = $articulo->movimientosInventario()
            ->with('documentable')
            ->orderByDesc('id')
            ->paginate(15);

        return MovimientoInventarioResource::collection($movimientos);
    }

    /**
     * Crea una orden de compra en `borrador` por proveedor con todos los artículos por pedir.
     *
     * No envía nada: quedan en borrador para revisar, corregir y enviar a mano.
     */
    public function generarOrdenesCompra(Request $request): JsonResponse
    {
        $porPedir = $this->porPedir($request->user()->articulos())
            ->with('catalogo.proveedor')
            ->get();

        // Un artículo cuyo catálogo o proveedor quedó borrado no tiene a quién pedírsele. Se omite
        // y se reporta, en lugar de crear una orden huérfana o fallar en silencio.
        [$pedibles, $omitidos] = $porPedir->partition(
            fn (Articulo $articulo) => $articulo->catalogo !== null && $articulo->catalogo->proveedor !== null
        );

        $ordenes = DB::transaction(function () use ($request, $pedibles) {
            $folio = ((int) OrdenCompra::where('user_id', $request->user()->id)->max('folio'));
            $creadas = [];

            foreach ($pedibles->groupBy(fn (Articulo $articulo) => $articulo->catalogo->proveedor_id) as $proveedorId => $articulos) {
                $creadas[] = $this->crearOrden($request, (int) $proveedorId, $articulos, ++$folio);
            }

            return $creadas;
        });

        return response()->json([
            'data' => OrdenCompraResource::collection(collect($ordenes)->map->load(['proveedor', 'lineas'])),
            'omitidos' => $omitidos->map(fn (Articulo $articulo) => [
                'id' => $articulo->id,
                'nombre' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'motivo' => 'El catálogo o el proveedor del artículo está eliminado.',
            ])->values(),
        ], 201);
    }

    /**
     * Reconstruye cada artículo desde su historial y reporta los que no cuadran.
     *
     * **Solo reporta.** Corregir en silencio borraría la evidencia de que algo estuvo mal; el
     * descuadre se arregla con un ajuste manual, que queda registrado como tal.
     */
    public function auditoria(Request $request): JsonResponse
    {
        $descuadres = $this->inventario->auditar($request->user());

        return response()->json([
            'data' => $descuadres->map(fn (array $descuadre) => [
                'articulo_id' => $descuadre['articulo']->id,
                'nombre' => $descuadre['articulo']->nombre,
                'modelo' => $descuadre['articulo']->modelo,
                'existencia_guardada' => $descuadre['existencia_guardada'],
                'existencia_calculada' => $descuadre['existencia_calculada'],
                'faltante_guardado' => $descuadre['faltante_guardado'],
                'faltante_calculado' => $descuadre['faltante_calculado'],
            ])->values(),
        ]);
    }

    /**
     * @param  Collection<int, Articulo>  $articulos
     */
    private function crearOrden(Request $request, int $proveedorId, $articulos, int $folio): OrdenCompra
    {
        $lineas = $articulos->map(fn (Articulo $articulo) => [
            'articulo_id' => $articulo->id,
            'cantidad' => $articulo->cantidad_sugerida,
            'descripcion' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            // Mismo criterio que cualquier orden de 012: la línea de compra se precarga con el
            // COSTO del artículo, no con su precio de venta.
            'precio_unitario' => (float) $articulo->costo_con_descuento,
            'descuento_tipo' => null,
            'descuento_valor' => null,
            // El artículo no guarda una tasa: la línea nace con la tasa general, igual que cuando
            // se captura a mano en `DocumentoLineas`, y es editable en el borrador.
            'tasa_iva' => TasaIva::General->value,
        ])->values()->all();

        $calculo = FacturaTotalesCalculator::calcular($lineas, null, null);

        $orden = $request->user()->ordenesCompra()->create([
            'proveedor_id' => $proveedorId,
            'folio' => $folio,
            'estado' => EstadoOrdenCompra::Borrador->value,
            'observaciones' => 'Generada automáticamente desde Existencias por artículos bajo mínimo.',
            'subtotal' => $calculo['subtotal'],
            'total_descuento' => $calculo['total_descuento'],
            'total_iva_16' => $calculo['total_iva_16'],
            'total_iva_0' => $calculo['total_iva_0'],
            'total_exento' => $calculo['total_exento'],
            'total' => $calculo['total'],
        ]);

        foreach ($lineas as $i => $linea) {
            $orden->lineas()->create([
                ...$linea,
                'importe' => $calculo['lineas'][$i]['importe'],
                'iva_importe' => $calculo['lineas'][$i]['iva_importe'],
            ]);
        }

        return $orden;
    }

    private function filtrar(Request $request): Builder
    {
        return $request->user()->articulos()
            ->when($request->string('q')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('nombre', 'like', $busqueda)->orWhere('modelo', 'like', $busqueda));
            })
            ->when($request->integer('catalogo') > 0, fn ($query) => $query->where('catalogo_id', $request->integer('catalogo')))
            ->when($request->integer('proveedor') > 0, function ($query) use ($request) {
                $query->whereHas('catalogo', fn ($q) => $q->where('proveedor_id', $request->integer('proveedor')));
            })
            ->when($request->boolean('por_pedir'), fn ($query) => $this->porPedir($query))
            // Sin "ver todos", la pantalla oculta el catálogo entero en ceros y deja solo lo que el
            // usuario realmente maneja: lo que tiene, lo que debe, o aquello para lo que definió un
            // mínimo.
            ->when(! $request->boolean('ver_todos'), function ($query) {
                $query->where(fn ($q) => $q->where('existencia', '>', 0)
                    ->orWhere('faltante_pendiente', '>', 0)
                    ->orWhere('minimo', '>', 0));
            });
    }

    /**
     * Un mínimo en 0 significa "no me avises"; un faltante pendiente siempre pide reposición.
     *
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function porPedir(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where(fn ($sub) => $sub->where('minimo', '>', 0)->whereColumn('existencia', '<=', 'minimo'))
                ->orWhere('faltante_pendiente', '>', 0);
        });
    }

    /**
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function ordenar(Builder $query, Request $request): Builder
    {
        $columna = (string) $request->string('orden');
        $direccion = $request->string('dir')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        if (! array_key_exists($columna, self::ORDENACIONES)) {
            return $query->orderBy('nombre');
        }

        return $query->orderByRaw(self::ORDENACIONES[$columna].' '.$direccion);
    }

    /**
     * @param  Builder<Articulo>  $query
     * @return array{unidades: int, dinero_invertido: float, beneficio_potencial: float, articulos_por_pedir: int}
     */
    private function totales(Builder $query): array
    {
        // Los alias llevan prefijo `suma_` a propósito: `Articulo` tiene accesores llamados
        // `dinero_invertido` y `beneficio_potencial`, y un alias con ese nombre queda eclipsado por
        // el accesor —que recalcularía sobre atributos no seleccionados y devolvería 0.
        $agregados = (clone $query)->selectRaw(
            'COALESCE(SUM(existencia), 0) as suma_unidades, '.
            'COALESCE(SUM(existencia * '.self::COSTO_TOTAL.'), 0) as suma_invertido, '.
            'COALESCE(SUM(existencia * '.self::UTILIDAD.'), 0) as suma_beneficio'
        )->first();

        return [
            'unidades' => (int) ($agregados->suma_unidades ?? 0),
            'dinero_invertido' => round((float) ($agregados->suma_invertido ?? 0), 2),
            'beneficio_potencial' => round((float) ($agregados->suma_beneficio ?? 0), 2),
            'articulos_por_pedir' => $this->porPedir(clone $query)->count(),
        ];
    }
}
