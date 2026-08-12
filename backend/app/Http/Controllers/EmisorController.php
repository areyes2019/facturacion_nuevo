<?php

namespace App\Http\Controllers;

use App\Http\Requests\Emisor\SubirLogoEmisorRequest;
use App\Http\Requests\Emisor\UpdateEmisorRequest;
use App\Http\Resources\EmisorResource;
use App\Models\Emisor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Datos fiscales del negocio que encabezan los tres documentos impresos
 * (ver 019-formato-pdf-documentos.md).
 *
 * A diferencia del resto del sistema, **estos endpoints no se scopean por usuario**: el emisor es
 * uno solo para toda la instalación, porque el timbrado usa una única llave de Facturapi y todos
 * los usuarios emiten con el mismo certificado.
 */
class EmisorController extends Controller
{
    public function show(): EmisorResource
    {
        return new EmisorResource(Emisor::actual());
    }

    /**
     * Crea la fila la primera vez y la actualiza siempre después. Nunca hay una segunda porque
     * `Emisor::actual()` devuelve la existente cuando la hay, y solo entrega una instancia nueva
     * cuando la tabla está vacía.
     */
    public function update(UpdateEmisorRequest $request): EmisorResource
    {
        $emisor = Emisor::actual();
        $emisor->fill($request->validated())->save();

        return new EmisorResource($emisor);
    }

    /**
     * Sirve el archivo del logo para la vista previa de Configuración.
     *
     * Los logos viven en el disco privado y no tienen URL propia; esta ruta es la única forma de
     * mirarlos, y va bajo `auth:sanctum` como el resto.
     */
    public function verLogo(string $tipo): Response
    {
        abort_unless(in_array($tipo, ['principal', 'marca'], true), 404);

        $contenido = Emisor::actual()->contenidoLogo($tipo);

        abort_if($contenido === null, 404);

        return response($contenido, 200, [
            'Content-Type' => str_starts_with($contenido, "\x89PNG") ? 'image/png' : 'image/jpeg',
        ]);
    }

    /**
     * Reemplaza uno de los dos logos. El archivo anterior se borra en el mismo acto: sin eso el
     * directorio acumula todos los logos que el usuario haya probado.
     */
    public function subirLogo(SubirLogoEmisorRequest $request): EmisorResource
    {
        $emisor = Emisor::actual();
        $columna = $request->validated('tipo') === 'marca' ? 'logo_marca_ruta' : 'logo_ruta';

        $anterior = $emisor->{$columna};

        $ruta = $request->file('archivo')->store(Emisor::DIRECTORIO_LOGOS, 'local');

        $emisor->fill([$columna => $ruta])->save();

        $this->borrarArchivo($anterior);

        return new EmisorResource($emisor);
    }

    public function eliminarLogo(string $tipo): JsonResponse
    {
        abort_unless(in_array($tipo, ['principal', 'marca'], true), 404);

        $emisor = Emisor::actual();
        $columna = $tipo === 'marca' ? 'logo_marca_ruta' : 'logo_ruta';

        $anterior = $emisor->{$columna};

        if ($emisor->exists) {
            $emisor->fill([$columna => null])->save();
        }

        $this->borrarArchivo($anterior);

        return response()->json(['eliminado' => true]);
    }

    private function borrarArchivo(?string $ruta): void
    {
        if (filled($ruta)) {
            Storage::disk('local')->delete($ruta);
        }
    }
}
