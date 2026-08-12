<?php

namespace App\Http\Requests\Articulos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CargarImagenesRequest extends FormRequest
{
    /**
     * Máximo de archivos por tanda. **Es `max_file_uploads` de PHP**, cuyo valor por defecto es
     * exactamente 20 (ver 020-imagenes-articulos.md).
     *
     * Rebasarlo no da error: PHP descarta en silencio los archivos que pasan de ese número, y el
     * reporte diría "20 asociadas" sobre una tanda de 50 sin mencionar las 30 que nunca llegaron.
     * Por eso el frontend parte la selección en tandas de este tamaño y aquí se rechaza de forma
     * visible cualquier petición que traiga más.
     */
    public const MAXIMO_ARCHIVOS = 20;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'archivos' => ['array', 'max:'.self::MAXIMO_ARCHIVOS],
            // Sin `image`: el formato se comprueba por contenido al procesar cada archivo, y un
            // archivo suelto que no sea imagen debe reportarse en el reporte, no tumbar la tanda
            // completa. El tope por archivo sí se valida aquí.
            'archivos.*' => ['file', 'max:10240'],
            'archivo' => ['file', 'mimes:zip', 'max:40960'],
        ];
    }

    /**
     * Exactamente uno de los dos campos. Aceptar ambos obligaría a decidir cuál gana, y no hay
     * ninguna respuesta que el usuario pueda anticipar.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $tieneArchivos = filled($this->file('archivos'));
                $tieneZip = $this->hasFile('archivo');

                if (! $tieneArchivos && ! $tieneZip) {
                    $validator->errors()->add('archivos', 'Selecciona imágenes o un archivo ZIP.');
                }

                if ($tieneArchivos && $tieneZip) {
                    $validator->errors()->add('archivos', 'Envía imágenes o un ZIP, no las dos cosas.');
                }
            },
        ];
    }
}
