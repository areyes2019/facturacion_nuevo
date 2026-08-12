<?php

namespace App\Http\Controllers;

use App\Http\Requests\Clientes\StoreClienteRequest;
use App\Http\Requests\Clientes\UpdateClienteRequest;
use App\Http\Resources\ClienteResource;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ClienteController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $clientes = $request->user()->clientes()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('razon_social', 'like', $search)
                        ->orWhere('nombre_comercial', 'like', $search)
                        ->orWhere('rfc', 'like', $search);
                });
            })
            ->orderBy('razon_social')
            ->paginate(15);

        return ClienteResource::collection($clientes);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreClienteRequest $request): ClienteResource
    {
        $cliente = $request->user()->clientes()->create($request->validated());

        return new ClienteResource($cliente);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Cliente $cliente): ClienteResource
    {
        abort_unless($cliente->user_id === $request->user()->id, 404);

        return new ClienteResource($cliente);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateClienteRequest $request, Cliente $cliente): ClienteResource
    {
        abort_unless($cliente->user_id === $request->user()->id, 404);

        $cliente->update($request->validated());

        return new ClienteResource($cliente);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Cliente $cliente): Response
    {
        abort_unless($cliente->user_id === $request->user()->id, 404);

        // No se valida aquí "facturas timbradas" (ver 004-gestion-clientes.md): el módulo de
        // facturación todavía no existe. Cuando exista, agregar esa verificación antes del delete.
        $cliente->delete();

        return response()->noContent();
    }
}
