<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalogos\AumentarCostosRequest;
use App\Http\Requests\Catalogos\StoreCatalogoRequest;
use App\Http\Requests\Catalogos\UpdateCatalogoRequest;
use App\Http\Resources\CatalogoResource;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class CatalogoProveedorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $catalogos = $request->user()->catalogos()
            ->with('proveedor')
            // `articulos_count` es lo que permite que la confirmación de borrado diga cuántos
            // artículos se lleva por delante (ver 021-mantenimiento-articulos-catalogos.md).
            ->withCount('articulos')
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('nombre', 'like', $search)
                        ->orWhereHas('proveedor', fn ($query) => $query->where('nombre_comercial', 'like', $search));
                });
            })
            ->orderBy('nombre')
            ->paginate(min($request->integer('per_page', 15), 100));

        return CatalogoResource::collection($catalogos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreCatalogoRequest $request): CatalogoResource
    {
        $catalogo = $request->user()->catalogos()->create($request->validated());

        return new CatalogoResource($catalogo->load('proveedor')->loadCount('articulos'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        return new CatalogoResource($catalogo->load('proveedor')->loadCount('articulos'));
    }

    /**
     * Impacto hipotético sobre los precios de los artículos del catálogo, sin persistir nada (ver
     * 011-precio-proveedor-utilidad.md y 021-mantenimiento-articulos-catalogos.md).
     *
     * Cubre los tres cambios que mueven precios en bloque: el descuento, el porcentaje de utilidad
     * y el aumento porcentual del costo. Devuelve, por artículo, el precio de proveedor, el costo y
     * el precio de venta que tendría con los valores propuestos.
     *
     * Los tres parámetros son opcionales y caen a lo que el catálogo ya tiene guardado: pedir la
     * vista previa de solo un aumento obligaría, si no, a reenviar valores que no se están
     * cambiando. Si se mueven varios a la vez, la vista previa muestra el resultado de aplicarlos
     * todos, que es lo que ocurriría al guardar.
     */
    public function impactoPrecios(Request $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $request->validate([
            'descuento' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'utilidad_porcentaje' => ['nullable', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
            'aumento_porcentaje' => [
                'nullable',
                'numeric',
                'gt:0',
                'lte:'.AumentarCostosRequest::MAXIMO_AUMENTO,
                'decimal:0,2',
            ],
        ]);

        $descuento = (float) ($request->input('descuento') ?? $catalogo->descuento);
        $utilidad = (float) ($request->input('utilidad_porcentaje') ?? $catalogo->utilidad_porcentaje);
        $aumento = (float) ($request->input('aumento_porcentaje') ?? 0);

        $articulos = $catalogo->articulos()
            ->get(['id', 'nombre', 'modelo', 'precio_proveedor', 'utilidad_porcentaje', 'costo_goma']);

        $impacto = $articulos->map(fn (Articulo $articulo): array => [
            'id' => $articulo->id,
            'nombre' => $articulo->nombre,
            'modelo' => $articulo->modelo,
            ...$this->proyectar($articulo, $descuento, $utilidad, $aumento),
        ]);

        return response()->json(['articulos' => $impacto]);
    }

    /**
     * Aumenta un porcentaje el costo de todos los artículos del catálogo (ver
     * 021-mantenimiento-articulos-catalogos.md).
     *
     * "Aumentar el costo un 5%" es subir un 5% el `precio_proveedor` —el único eslabón capturado a
     * mano— y volver a recorrer la cadena desde ahí. El descuento del catálogo, los porcentajes de
     * utilidad y el costo de goma no se tocan, así que el margen se conserva proporcionalmente.
     *
     * Se recorre artículo por artículo con `PrecioArticuloCalculator` en vez de hacer un
     * `UPDATE ... SET precio_proveedor = precio_proveedor * 1.05` en SQL: el techo a 2 decimales del
     * markup (CEIL) no es portable entre MySQL y SQLite —la misma razón que ya obligó a resolver en
     * PHP el recálculo por descuento de `Catalogo::booted()`— y un precio calculado por un camino
     * distinto al de todos los demás produce diferencias de un centavo según por dónde se entró a
     * cambiarlo.
     */
    public function aumentarCostos(AumentarCostosRequest $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $aumento = (float) $request->validated()['aumento_porcentaje'];
        $descuento = (float) $catalogo->descuento;
        $utilidad = (float) $catalogo->utilidad_porcentaje;

        $actualizados = DB::transaction(function () use ($catalogo, $descuento, $utilidad, $aumento): int {
            $actualizados = 0;

            foreach ($catalogo->articulos()->get() as $articulo) {
                $proyeccion = $this->proyectar($articulo, $descuento, $utilidad, $aumento);

                $articulo->update([
                    'precio_proveedor' => $proyeccion['precio_proveedor'],
                    'costo_con_descuento' => $proyeccion['costo_con_descuento'],
                    'precio_unitario_sin_iva' => $proyeccion['precio_unitario_sin_iva'],
                ]);
                $actualizados++;
            }

            return $actualizados;
        });

        return response()->json(['actualizados' => $actualizados]);
    }

    /**
     * Cadena de precios que tendría un artículo con los valores propuestos, sin persistir nada.
     *
     * La usan **la vista previa y el aumento real**, y esa es toda su razón de ser: una vista previa
     * que no coincide al centavo con el resultado es peor que no tenerla.
     *
     * `costo_total` viaja en el resultado para que la vista previa pueda mostrarlo, pero no se
     * persiste: es la suma de dos columnas (ver 014-costo-elaboracion-goma.md).
     *
     * @return array{precio_proveedor: float, costo_con_descuento: float, costo_total: float, precio_unitario_sin_iva: float}
     */
    private function proyectar(Articulo $articulo, float $descuento, float $utilidad, float $aumento): array
    {
        $precioProveedor = $aumento > 0
            ? PrecioArticuloCalculator::precioProveedorAumentado((float) $articulo->precio_proveedor, $aumento)
            : (float) $articulo->precio_proveedor;

        // Los artículos con porcentaje propio conservan el suyo; los que heredan el del catálogo
        // siguen heredándolo (ver 011-precio-proveedor-utilidad.md).
        $cadena = PrecioArticuloCalculator::calcularCadena(
            $precioProveedor,
            $descuento,
            (float) ($articulo->utilidad_porcentaje ?? $utilidad),
            (float) $articulo->costo_goma,
        );

        return ['precio_proveedor' => $precioProveedor, ...$cadena];
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCatalogoRequest $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $catalogo->update($request->validated());

        return new CatalogoResource($catalogo->load('proveedor')->loadCount('articulos'));
    }

    /**
     * Elimina el catálogo **junto con todos sus artículos** (ver
     * 021-mantenimiento-articulos-catalogos.md).
     *
     * Antes respondía `409` si el catálogo tenía artículos, lo que dejaba al usuario sin salida:
     * para borrar un catálogo de 800 artículos tendría que vaciarlo a mano primero. Ahora arrastra
     * su contenido, con el nombre del catálogo escrito a mano como cerrojo en el frontend.
     *
     * Los artículos se marcan de **una sola instrucción** (`delete()` sobre la relación, que con
     * `SoftDeletes` es un único `UPDATE ... SET deleted_at`), no recorriéndolos uno por uno: con
     * cientos de artículos la diferencia es entre milisegundos y una petición agotada. El matiz es
     * que un borrado masivo **no dispara los eventos de modelo** de cada artículo; hoy no hay
     * ninguno enganchado al borrado de `Articulo` —el único `booted()` en juego es el de `Catalogo`,
     * y escucha `updated`—, pero queda anotado para quien agregue uno.
     *
     * Las imágenes se quedan en disco: el borrado es lógico y las fotos no están en git ni en
     * ningún respaldo (ver 020-imagenes-articulos.md).
     */
    public function destroy(Request $request, Catalogo $catalogo): Response
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        // O se van el catálogo y su contenido, o no se va ninguno.
        DB::transaction(function () use ($catalogo): void {
            $catalogo->articulos()->delete();
            $catalogo->delete();
        });

        return response()->noContent();
    }
}
