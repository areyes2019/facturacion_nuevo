<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cuentas\StoreCuentaRequest;
use App\Http\Requests\Cuentas\UpdateCuentaRequest;
use App\Http\Resources\CuentaResource;
use App\Models\Cuenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CuentaController extends Controller
{
    /**
     * Listado paginado con búsqueda por nombre y filtro por estado. Alimenta también los
     * selectores de cuenta del frontend (ver 010-tesoreria.md).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $cuentas = $request->user()->cuentas()
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $query->where('nombre', 'like', '%'.$request->string('search')->trim().'%');
            })
            ->when($request->filled('activa'), fn ($query) => $query->where('activa', $request->boolean('activa')))
            ->orderBy('nombre')
            ->paginate(min($request->integer('per_page', 15), 100));

        return CuentaResource::collection($cuentas);
    }

    /**
     * UC-07: saldo actual de todas las cuentas del usuario (activas e inactivas), sin paginar y
     * sin desglose de movimientos, más el total global. Es una lectura simple porque
     * `saldo_actual` es una columna persistida, no un SUM al vuelo.
     */
    public function saldos(Request $request): JsonResponse
    {
        $cuentas = $request->user()->cuentas()->orderBy('nombre')->get();

        return response()->json([
            'data' => CuentaResource::collection($cuentas),
            'total_global' => round((float) $cuentas->sum(fn (Cuenta $cuenta) => (float) $cuenta->saldo_actual), 2),
        ]);
    }

    public function store(StoreCuentaRequest $request): CuentaResource
    {
        $datos = $request->validated();

        $cuenta = $request->user()->cuentas()->create($datos + [
            // Una cuenta recién creada no tiene movimientos, así que su saldo actual arranca igual
            // al inicial.
            'saldo_actual' => $datos['saldo_inicial'],
        ]);

        return new CuentaResource($cuenta);
    }

    public function show(Request $request, Cuenta $cuenta): CuentaResource
    {
        abort_unless($cuenta->user_id === $request->user()->id, 404);

        return new CuentaResource($cuenta);
    }

    /**
     * Edita nombre, tipo y estado. `saldo_inicial` es inmutable: no lo valida UpdateCuentaRequest,
     * así que si se envía en el PUT simplemente se ignora.
     */
    public function update(UpdateCuentaRequest $request, Cuenta $cuenta): CuentaResource
    {
        abort_unless($cuenta->user_id === $request->user()->id, 404);

        $cuenta->update($request->validated());

        return new CuentaResource($cuenta);
    }

    /**
     * Borrado físico, sin soft delete: solo se permite si la cuenta nunca tuvo ningún movimiento.
     * Si ya tiene historial, la única vía es desactivarla (mismo patrón de 409 que
     * Catalogo/Proveedor en 009/005).
     */
    public function destroy(Request $request, Cuenta $cuenta): Response
    {
        abort_unless($cuenta->user_id === $request->user()->id, 404);

        abort_if(
            $cuenta->movimientos()->exists(),
            409,
            'No se puede eliminar: la cuenta tiene movimientos registrados'
        );

        $cuenta->delete();

        return response()->noContent();
    }
}
