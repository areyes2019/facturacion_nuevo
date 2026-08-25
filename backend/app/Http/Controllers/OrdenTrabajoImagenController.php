<?php

namespace App\Http\Controllers;

use App\Http\Requests\Produccion\SubirImagenOrdenTrabajoRequest;
use App\Http\Resources\OrdenTrabajoResource;
use App\Models\OrdenTrabajo;
use App\Services\ImagenOrdenTrabajoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imagen de diseño de una Orden de Trabajo (ver 038-produccion-ordenes-trabajo.md). Mismo patrón
 * que `ArticuloImagenController` (020): los archivos viven en el disco privado, nunca en el docroot.
 */
class OrdenTrabajoImagenController extends Controller
{
    public function __construct(private readonly ImagenOrdenTrabajoService $imagenes) {}

    public function show(Request $request, OrdenTrabajo $orden): Response
    {
        abort_unless($orden->user_id === $request->user()->id, 404);

        $contenido = $this->imagenes->contenido($orden);

        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    public function store(SubirImagenOrdenTrabajoRequest $request, OrdenTrabajo $orden): OrdenTrabajoResource
    {
        abort_unless($orden->user_id === $request->user()->id, 404);

        try {
            $this->imagenes->guardar($orden, $request->file('archivo')->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => 'El archivo '.$e->getMessage().'.']);
        }

        return new OrdenTrabajoResource($orden->fresh());
    }

    public function destroy(Request $request, OrdenTrabajo $orden): JsonResponse
    {
        abort_unless($orden->user_id === $request->user()->id, 404);

        $this->imagenes->eliminar($orden);

        return response()->json(['eliminado' => true]);
    }
}
