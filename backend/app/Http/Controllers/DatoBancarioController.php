<?php

namespace App\Http\Controllers;

use App\Http\Requests\DatosBancarios\GuardarDatoBancarioRequest;
use App\Http\Requests\DatosBancarios\ReordenarDatosBancariosRequest;
use App\Http\Requests\DatosBancarios\SubirLogoBancoRequest;
use App\Http\Resources\DatoBancarioResource;
use App\Models\DatoBancario;
use App\Services\LogoBancoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cuentas bancarias que se imprimen en la cotización para que el cliente pague
 * (ver 026-datos-bancarios-cotizacion.md).
 *
 * Como los del emisor en 019, **estos endpoints no se scopean por usuario**: los datos bancarios
 * son del negocio que emite, que es uno solo para toda la instalación.
 */
class DatoBancarioController extends Controller
{
    /**
     * La lista completa, incluidos los ocultos: esta pantalla los administra, no solo los muestra.
     */
    public function index(): AnonymousResourceCollection
    {
        return DatoBancarioResource::collection(
            DatoBancario::query()->orderBy('orden')->orderBy('id')->get()
        );
    }

    public function store(GuardarDatoBancarioRequest $request): DatoBancarioResource
    {
        $banco = new DatoBancario($request->validated());
        $banco->orden = DatoBancario::siguienteOrden();
        $banco->save();

        return new DatoBancarioResource($banco);
    }

    public function update(GuardarDatoBancarioRequest $request, DatoBancario $dato): DatoBancarioResource
    {
        $dato->fill($request->validated())->save();

        return new DatoBancarioResource($dato);
    }

    /**
     * Baja definitiva. Las cotizaciones ya creadas no se enteran: llevan su propia foto de los
     * datos bancarios (ver `Cotizacion::datos_bancarios`).
     *
     * **El archivo del logo se queda en disco a propósito.** Esa foto congelada guarda la ruta del
     * icono, y borrarlo dejaría a las cotizaciones viejas imprimiendo el nombre del banco sin él.
     * Es el mismo criterio que 020 aplica al artículo dado de baja.
     */
    public function destroy(DatoBancario $dato): Response
    {
        $dato->delete();

        return response()->noContent();
    }

    /**
     * Sube o reemplaza el icono del banco. El archivo anterior se borra dentro del servicio.
     */
    public function subirLogo(SubirLogoBancoRequest $request, DatoBancario $dato, LogoBancoService $logos): DatoBancarioResource
    {
        try {
            $logos->guardar($dato, $request->file('archivo')->getRealPath());
        } catch (RuntimeException $e) {
            // El motivo viene del procesador y nombra la causa concreta ("no es una imagen JPG,
            // PNG ni WEBP"), que es lo que el usuario necesita para corregir.
            abort(422, $e->getMessage());
        }

        return new DatoBancarioResource($dato);
    }

    public function eliminarLogo(DatoBancario $dato, LogoBancoService $logos): JsonResponse
    {
        $logos->eliminar($dato);

        return response()->json(['eliminado' => true]);
    }

    /**
     * Sirve el archivo para la vista previa de Configuración. Los iconos viven en el disco privado
     * y no tienen URL propia: ésta es la única forma de mirarlos, y va bajo `auth:sanctum`.
     */
    public function verLogo(DatoBancario $dato): Response
    {
        $contenido = $dato->contenidoLogo();

        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => 'image/webp',
            // El nombre del archivo cambia en cada reemplazo, así que el navegador puede guardarlo
            // una semana sin riesgo de mostrar uno viejo (ver `logo_version`).
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Reasigna `orden` según la secuencia recibida, en una transacción: un reordenamiento a medias
     * dejaría la lista en un orden que el usuario no pidió y que no se parece al anterior.
     */
    public function reordenar(ReordenarDatosBancariosRequest $request): AnonymousResourceCollection
    {
        DB::transaction(function () use ($request) {
            foreach ($request->validated('ids') as $posicion => $id) {
                DatoBancario::query()->whereKey($id)->update(['orden' => $posicion + 1]);
            }
        });

        return $this->index();
    }
}
