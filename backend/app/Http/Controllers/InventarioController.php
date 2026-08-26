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
use App\Models\Existencia;
use App\Models\OrdenCompra;
use App\Services\FacturaTotalesCalculator;
use App\Services\InventarioService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Pantalla de Existencias (ver 017-inventario.md): la bodega curada por el usuario, no el catálogo
 * completo de Artículos. Solo lista artículos con fila en `existencias`.
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
    private const COSTO_TOTAL = '(articulos.costo_con_descuento + articulos.costo_goma)';

    private const UTILIDAD = '(articulos.precio_unitario_sin_iva - articulos.costo_con_descuento - articulos.costo_goma)';

    /** Columnas ordenables del listado. */
    private const ORDENACIONES = [
        'nombre' => 'articulos.nombre',
        'modelo' => 'articulos.modelo',
        'existencia' => 'existencias.existencia',
        'faltante' => 'existencias.faltante_pendiente',
        'invertido' => 'existencias.existencia * '.self::COSTO_TOTAL,
        'beneficio' => 'existencias.existencia * '.self::UTILIDAD,
    ];

    public function __construct(private readonly InventarioService $inventario) {}

    /**
     * Listado paginado con los totales del conjunto **filtrado completo**.
     *
     * Los totales no se suman sobre los 15 registros de la página: si lo hicieran, el "dinero
     * invertido" cambiaría al pasar de página y sería un número bonito y falso. Son una consulta
     * agregada aparte, resuelta en la base de datos, que viaja en `meta.totales`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtrada = $this->filtrar($request);

        $existencias = $this->ordenar(clone $filtrada, $request)
            ->select('existencias.*')
            ->with('articulo.catalogo.proveedor')
            ->paginate(15)
            ->withQueryString();

        return InventarioResource::collection($existencias)
            ->additional(['meta' => ['totales' => $this->totales(clone $filtrada)]]);
    }

    /**
     * Umbrales de reposición. No genera movimiento: cambiar un umbral no mueve piezas.
     */
    public function parametros(ActualizarParametrosInventarioRequest $request, Articulo $articulo): InventarioResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $existencia = $articulo->existencia;
        abort_if($existencia === null, 404);

        $existencia->forceFill([
            'minimo' => (int) $request->validated('minimo'),
            'maximo' => $request->validated('maximo'),
        ])->save();

        return new InventarioResource($existencia->fresh(['articulo.catalogo.proveedor']));
    }

    /**
     * Ajuste manual: fija la cantidad final y pone el faltante en cero.
     *
     * Pasar un artículo a existencias por primera vez usa este mismo endpoint; no hay un alta
     * aparte, porque declarar "tengo 10" es la misma operación se venga de "nunca marcado" o de 7.
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

        return new InventarioResource($articulo->existencia()->first()->load('articulo.catalogo.proveedor'));
    }

    /**
     * Quita el artículo de existencias (borrado lógico de su fila). No bloquea aunque tenga
     * existencia o faltante distintos de cero: el historial se conserva y volver a marcarlo
     * restaura la misma fila.
     */
    public function destroy(Request $request, Articulo $articulo): Response
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $this->inventario->quitar($articulo);

        return response()->noContent();
    }

    /**
     * Historial del artículo, más reciente primero. Solo lectura. No exige fila vigente en
     * `existencias`: el historial sobrevive a quitar el artículo.
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
        $porPedir = $this->porPedir($this->baseQuery($request))
            ->select('existencias.*')
            ->with('articulo.catalogo.proveedor')
            ->get();

        // Un artículo cuyo catálogo o proveedor quedó borrado no tiene a quién pedírsele. Se omite
        // y se reporta, en lugar de crear una orden huérfana o fallar en silencio.
        [$pedibles, $omitidos] = $porPedir->partition(
            fn (Existencia $existencia) => $existencia->articulo->catalogo !== null && $existencia->articulo->catalogo->proveedor !== null
        );

        $ordenes = DB::transaction(function () use ($request, $pedibles) {
            $folio = ((int) OrdenCompra::where('user_id', $request->user()->id)->max('folio'));
            $creadas = [];

            foreach ($pedibles->groupBy(fn (Existencia $existencia) => $existencia->articulo->catalogo->proveedor_id) as $proveedorId => $existenciasGrupo) {
                $creadas[] = $this->crearOrden($request, (int) $proveedorId, $existenciasGrupo, ++$folio);
            }

            return $creadas;
        });

        return response()->json([
            'data' => OrdenCompraResource::collection(collect($ordenes)->map->load(['proveedor', 'lineas'])),
            'omitidos' => $omitidos->map(fn (Existencia $existencia) => [
                'id' => $existencia->articulo->id,
                'nombre' => $existencia->articulo->nombre,
                'modelo' => $existencia->articulo->modelo,
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
     * @param  Collection<int, Existencia>  $existencias
     */
    private function crearOrden(Request $request, int $proveedorId, $existencias, int $folio): OrdenCompra
    {
        $lineas = $existencias->map(fn (Existencia $existencia) => [
            'articulo_id' => $existencia->articulo->id,
            'cantidad' => $existencia->cantidad_sugerida,
            'descripcion' => $existencia->articulo->nombre,
            'modelo' => $existencia->articulo->modelo,
            // Mismo criterio que cualquier orden de 012: la línea de compra se precarga con el
            // COSTO del artículo, no con su precio de venta.
            'precio_unitario' => (float) $existencia->articulo->costo_con_descuento,
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

    /**
     * Existencias del usuario autenticado, con su artículo (no borrado) ya unido para poder
     * filtrar, ordenar y sumar sobre columnas de las dos tablas.
     *
     * @return Builder<Existencia>
     */
    private function baseQuery(Request $request): Builder
    {
        return Existencia::query()
            ->join('articulos', 'articulos.id', '=', 'existencias.articulo_id')
            ->where('articulos.user_id', $request->user()->id)
            ->whereNull('articulos.deleted_at');
    }

    /**
     * @return Builder<Existencia>
     */
    private function filtrar(Request $request): Builder
    {
        return $this->baseQuery($request)
            ->when($request->string('q')->trim()->isNotEmpty(), function ($query) use ($request) {
                $busqueda = '%'.$request->string('q')->trim().'%';
                $query->where(fn ($q) => $q->where('articulos.nombre', 'like', $busqueda)->orWhere('articulos.modelo', 'like', $busqueda));
            })
            ->when($request->integer('catalogo') > 0, fn ($query) => $query->where('articulos.catalogo_id', $request->integer('catalogo')))
            ->when($request->integer('proveedor') > 0, function ($query) use ($request) {
                $query->whereExists(function ($sub) use ($request) {
                    $sub->selectRaw('1')
                        ->from('catalogos')
                        ->whereColumn('catalogos.id', 'articulos.catalogo_id')
                        ->where('catalogos.proveedor_id', $request->integer('proveedor'));
                });
            })
            ->when($request->boolean('por_pedir'), fn ($query) => $this->porPedir($query));
    }

    /**
     * Un mínimo en 0 significa "no me avises"; un faltante pendiente siempre pide reposición.
     *
     * Estrictamente menor que, no "menor o igual" — mismo motivo que `Existencia::porPedir()`: sin
     * máximo capturado el techo de la sugerencia es el mínimo, y en `existencia == mínimo` la
     * cantidad sugerida sería 0.
     *
     * @param  Builder<Existencia>  $query
     * @return Builder<Existencia>
     */
    private function porPedir(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where(fn ($sub) => $sub->where('existencias.minimo', '>', 0)->whereColumn('existencias.existencia', '<', 'existencias.minimo'))
                ->orWhere('existencias.faltante_pendiente', '>', 0);
        });
    }

    /**
     * @param  Builder<Existencia>  $query
     * @return Builder<Existencia>
     */
    private function ordenar(Builder $query, Request $request): Builder
    {
        $columna = (string) $request->string('orden');
        $direccion = $request->string('dir')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        if (! array_key_exists($columna, self::ORDENACIONES)) {
            return $query->orderBy('articulos.nombre');
        }

        return $query->orderByRaw(self::ORDENACIONES[$columna].' '.$direccion);
    }

    /**
     * @param  Builder<Existencia>  $query
     * @return array{unidades: int, dinero_invertido: float, beneficio_potencial: float, total_general: float, articulos_por_pedir: int}
     */
    private function totales(Builder $query): array
    {
        $agregados = (clone $query)->selectRaw(
            'COALESCE(SUM(existencias.existencia), 0) as suma_unidades, '.
            'COALESCE(SUM(existencias.existencia * '.self::COSTO_TOTAL.'), 0) as suma_invertido, '.
            'COALESCE(SUM(existencias.existencia * '.self::UTILIDAD.'), 0) as suma_beneficio'
        )->first();

        $invertido = round((float) ($agregados->suma_invertido ?? 0), 2);
        $beneficio = round((float) ($agregados->suma_beneficio ?? 0), 2);

        return [
            'unidades' => (int) ($agregados->suma_unidades ?? 0),
            'dinero_invertido' => $invertido,
            'beneficio_potencial' => $beneficio,
            'total_general' => round($invertido + $beneficio, 2),
            'articulos_por_pedir' => $this->porPedir(clone $query)->count(),
        ];
    }
}
