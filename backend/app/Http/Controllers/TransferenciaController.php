<?php

namespace App\Http\Controllers;

use App\Http\Requests\Movimientos\StoreTransferenciaRequest;
use App\Http\Resources\MovimientoResource;
use App\Services\TesoreriaService;
use Illuminate\Http\JsonResponse;

/**
 * UC-04. Endpoint dedicado en vez de un tipo más de `POST /api/v1/movimientos` porque su forma y
 * sus validaciones son distintas: dos cuentas en vez de una, y dos filas persistidas en vez de una
 * (ver 010-tesoreria.md).
 */
class TransferenciaController extends Controller
{
    public function __construct(private readonly TesoreriaService $tesoreria) {}

    /**
     * Devuelve las dos filas creadas (la que resta en la cuenta origen y la que suma en la
     * destino), ya vinculadas por su `transferencia_id` compartido. El 201 se fija a mano porque
     * una colección de resources responde 200 por defecto.
     */
    public function store(StoreTransferenciaRequest $request): JsonResponse
    {
        $movimientos = $this->tesoreria->registrarTransferencia($request->user(), $request->validated());

        return MovimientoResource::collection(
            collect($movimientos)->each(fn ($movimiento) => $movimiento->load('cuenta'))
        )->response()->setStatusCode(201);
    }
}
