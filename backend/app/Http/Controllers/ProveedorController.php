<?php

namespace App\Http\Controllers;

use App\Http\Requests\Proveedores\StoreProveedorRequest;
use App\Http\Requests\Proveedores\UpdateProveedorRequest;
use App\Http\Resources\ProveedorResource;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ProveedorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $proveedores = $request->user()->proveedores()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('nombre_comercial', 'like', $search)
                        ->orWhere('nombre_contacto', 'like', $search);
                });
            })
            ->orderBy('nombre_comercial')
            ->paginate(min($request->integer('per_page', 15), 100));

        return ProveedorResource::collection($proveedores);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreProveedorRequest $request): ProveedorResource
    {
        $proveedor = $request->user()->proveedores()->create($request->validated());

        return new ProveedorResource($proveedor);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Proveedor $proveedor): ProveedorResource
    {
        abort_unless($proveedor->user_id === $request->user()->id, 404);

        return new ProveedorResource($proveedor);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateProveedorRequest $request, Proveedor $proveedor): ProveedorResource
    {
        abort_unless($proveedor->user_id === $request->user()->id, 404);

        $proveedor->update($request->validated());

        return new ProveedorResource($proveedor);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Proveedor $proveedor): Response
    {
        abort_unless($proveedor->user_id === $request->user()->id, 404);

        abort_if(
            $proveedor->tieneOrdenesActivas(),
            409,
            'No se puede eliminar: tiene órdenes de compra activas'
        );

        $proveedor->delete();

        return response()->noContent();
    }
}
