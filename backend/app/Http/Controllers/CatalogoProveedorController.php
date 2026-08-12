<?php

namespace App\Http\Controllers;

use App\Http\Requests\Catalogos\StoreCatalogoRequest;
use App\Http\Requests\Catalogos\UpdateCatalogoRequest;
use App\Http\Resources\CatalogoResource;
use App\Models\Catalogo;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CatalogoProveedorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $catalogos = $request->user()->catalogos()
            ->with('proveedor')
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

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Impacto hipotético de cambiar el descuento o el porcentaje de utilidad del catálogo sobre
     * los precios de sus artículos, sin persistir nada (ver 011-precio-proveedor-utilidad.md).
     *
     * El frontend lo usa para mostrar una vista previa antes de guardar. Devuelve, por artículo,
     * el costo con descuento y el precio de venta que tendría con los valores propuestos.
     */
    public function impactoPrecios(Request $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $request->validate([
            'descuento' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'utilidad_porcentaje' => ['required', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
        ]);

        $descuento = (float) $request->input('descuento');
        $utilidad = (float) $request->input('utilidad_porcentaje');

        $articulos = $catalogo->articulos()
            ->get(['id', 'nombre', 'modelo', 'precio_proveedor', 'utilidad_porcentaje', 'costo_goma']);

        $impacto = $articulos->map(function ($articulo) use ($descuento, $utilidad) {
            $utilidadEfectiva = $articulo->utilidad_porcentaje ?? $utilidad;
            $cadena = PrecioArticuloCalculator::calcularCadena(
                (float) $articulo->precio_proveedor,
                $descuento,
                (float) $utilidadEfectiva,
                (float) $articulo->costo_goma,
            );

            return [
                'id' => $articulo->id,
                'nombre' => $articulo->nombre,
                'modelo' => $articulo->modelo,
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'costo_total' => $cadena['costo_total'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ];
        });

        return response()->json(['articulos' => $impacto]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateCatalogoRequest $request, Catalogo $catalogo): CatalogoResource
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $catalogo->update($request->validated());

        return new CatalogoResource($catalogo->load('proveedor'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Catalogo $catalogo): Response
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        abort_if(
            $catalogo->articulos()->exists(),
            409,
            'No se puede eliminar: el catálogo tiene artículos asociados'
        );

        $catalogo->delete();

        return response()->noContent();
    }
}
