<?php

namespace App\Http\Controllers;

use App\Http\Requests\Articulos\CargarImagenesRequest;
use App\Http\Requests\Articulos\SubirImagenArticuloRequest;
use App\Http\Resources\ArticuloResource;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Services\CargaMasivaImagenesService;
use App\Services\ImagenArticuloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Imágenes de artículo (ver 020-imagenes-articulos.md).
 *
 * Los archivos viven en el disco privado y salen por aquí, nunca desde el docroot: en producción
 * `symlink` está desactivada —así que `storage:link` no existe— y, sobre todo, el despliegue del
 * frontend borra del docroot todo lo que no venga en el build, lo que se habría llevado por delante
 * todas las fotos en el siguiente despliegue (ver 018-despliegue-hostinger.md).
 */
class ArticuloImagenController extends Controller
{
    public function __construct(
        private readonly ImagenArticuloService $imagenes,
        private readonly CargaMasivaImagenesService $cargaMasiva,
    ) {}

    /**
     * Entrega el binario de la imagen. La sesión ya la validó el middleware; aquí solo se comprueba
     * que el artículo sea del usuario, mismo patrón que el resto del módulo.
     */
    public function show(Request $request, Articulo $articulo): Response
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $contenido = $this->imagenes->contenido($articulo);

        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => 'image/webp',
            // El navegador puede guardarla una semana sin riesgo: cada reemplazo genera un nombre
            // nuevo, `imagen_version` cambia con él y la URL deja de ser la misma.
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    public function store(SubirImagenArticuloRequest $request, Articulo $articulo): ArticuloResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        try {
            $this->imagenes->guardar($articulo, $request->file('archivo')->getRealPath());
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['archivo' => 'El archivo '.$e->getMessage().'.']);
        }

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    public function destroy(Request $request, Articulo $articulo): JsonResponse
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $this->imagenes->eliminar($articulo);

        return response()->json(['eliminado' => true]);
    }

    /**
     * Carga masiva: empareja los archivos con los artículos del catálogo por el nombre del archivo.
     *
     * Acepta una tanda de imágenes sueltas o un ZIP plano. Responde con la misma forma de reporte
     * que la importación CSV de 009, cambiando `fila` por `archivo`.
     */
    public function cargaMasiva(CargarImagenesRequest $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        if ($request->hasFile('archivo')) {
            $archivos = $this->cargaMasiva->extraerZip($request->file('archivo')->getRealPath());

            try {
                $reporte = $this->cargaMasiva->procesar($catalogo, $archivos);
            } finally {
                $this->cargaMasiva->limpiarTemporales($archivos);
            }

            return response()->json($reporte);
        }

        $archivos = array_map(
            fn ($archivo) => [$archivo->getClientOriginalName(), $archivo->getRealPath()],
            $request->file('archivos'),
        );

        return response()->json($this->cargaMasiva->procesar($catalogo, $archivos));
    }
}
