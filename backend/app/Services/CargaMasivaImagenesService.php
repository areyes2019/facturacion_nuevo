<?php

namespace App\Services;

use App\Models\Articulo;
use App\Models\Catalogo;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Empareja archivos de imagen con los artículos de un catálogo por el nombre del archivo
 * (ver 020-imagenes-articulos.md).
 *
 * Devuelve siempre un reporte con la misma forma que el de la importación CSV de 009, cambiando
 * `fila` por `archivo`: aquí lo que identifica al elemento rechazado es su nombre.
 */
class CargaMasivaImagenesService
{
    /**
     * Tope de contenido descomprimido de un ZIP. Protege del accidente de comprimir la carpeta
     * equivocada y llenar el disco del plan compartido.
     */
    private const MAXIMO_DESCOMPRIMIDO = 400 * 1024 * 1024;

    public function __construct(private readonly ImagenArticuloService $imagenes) {}

    /**
     * Procesa una tanda de archivos ya subidos, cada uno como `[nombre original, ruta temporal]`.
     *
     * @param  array<int, array{0: string, 1: string}>  $archivos
     * @return array{asociadas: int, errores: array<int, array{archivo: string, motivo: string}>}
     */
    public function procesar(Catalogo $catalogo, array $archivos): array
    {
        $porModelo = $this->modelosDelCatalogo($catalogo);

        $errores = [];

        /** @var array<int, string> $asignados  articulo_id => nombre del archivo que le tocó */
        $asignados = [];

        foreach ($archivos as [$nombre, $rutaTemporal]) {
            $clave = $this->normalizar(pathinfo($nombre, PATHINFO_FILENAME));
            $ids = $porModelo[$clave] ?? [];

            if ($ids === []) {
                $errores[] = [
                    'archivo' => $nombre,
                    'motivo' => 'no hay ningún artículo con modelo "'.pathinfo($nombre, PATHINFO_FILENAME).'" en este catálogo',
                ];

                continue;
            }

            // `modelo` es texto libre (006) y puede repetirse dentro de un catálogo. Elegir uno al
            // azar dejaría la foto en el artículo equivocado sin que nadie se entere, que es peor
            // que no ponerla.
            if (count($ids) > 1) {
                $errores[] = [
                    'archivo' => $nombre,
                    'motivo' => 'hay '.count($ids).' artículos con modelo "'.pathinfo($nombre, PATHINFO_FILENAME).'" en este catálogo',
                ];

                continue;
            }

            $articulo = Articulo::find($ids[0]);

            if ($articulo === null) {
                continue;
            }

            try {
                $this->imagenes->guardar($articulo, $rutaTemporal);
            } catch (RuntimeException $e) {
                $errores[] = ['archivo' => $nombre, 'motivo' => $e->getMessage()];

                continue;
            }

            // Gana el último procesado, coherente con que subir una imagen reemplaza la anterior.
            // El desplazado se reporta para que el usuario sepa que ese archivo no quedó en ningún
            // lado, en vez de creer que se guardaron los dos.
            if (isset($asignados[$articulo->id])) {
                $errores[] = [
                    'archivo' => $asignados[$articulo->id],
                    'motivo' => 'otro archivo de esta carga se asignó al mismo artículo',
                ];
            }

            $asignados[$articulo->id] = $nombre;
        }

        return ['asociadas' => count($asignados), 'errores' => $errores];
    }

    /**
     * Extrae un ZIP plano a archivos temporales y devuelve la lista lista para `procesar`.
     *
     * Quien llama debe borrar las rutas temporales al terminar.
     *
     * @return array<int, array{0: string, 1: string}>
     *
     * @throws ValidationException si el ZIP trae carpetas, no se puede abrir o se pasa del tope
     */
    public function extraerZip(string $rutaZip): array
    {
        $zip = new ZipArchive;

        if ($zip->open($rutaZip) !== true) {
            throw ValidationException::withMessages([
                'archivo' => 'No se pudo abrir el archivo ZIP.',
            ]);
        }

        try {
            $this->validarZipPlano($zip);

            $archivos = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $nombre = (string) $zip->getNameIndex($i);

                $stream = $zip->getStream($nombre);

                if ($stream === false) {
                    continue;
                }

                $temporal = (string) tempnam(sys_get_temp_dir(), 'img');
                $destino = fopen($temporal, 'w');
                stream_copy_to_stream($stream, $destino);
                fclose($destino);
                fclose($stream);

                // Del ZIP se usa solo el nombre del archivo, nunca la ruta que trae escrita dentro:
                // esa ruta puede pedir escribir fuera del directorio de destino. La comprobación de
                // ZIP plano ya lo cierra, y esto lo cierra otra vez por si aquella cambiara.
                $archivos[] = [basename($nombre), $temporal];
            }

            return $archivos;
        } finally {
            $zip->close();
        }
    }

    /**
     * Un ZIP con carpetas se rechaza entero, no se procesa lo que se pueda.
     *
     * Es deliberado: si se entrara solo a las subcarpetas, el resultado dependería de cómo quedó
     * armado el comprimido, y el usuario no tendría forma de saber por qué la misma selección de
     * fotos se comporta distinto según cómo la comprimió.
     */
    private function validarZipPlano(ZipArchive $zip): void
    {
        $descomprimido = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $estadisticas = $zip->statIndex($i);

            if ($estadisticas === false) {
                continue;
            }

            $nombre = (string) $estadisticas['name'];

            if (str_contains($nombre, '/') || str_contains($nombre, '\\')) {
                throw ValidationException::withMessages([
                    'archivo' => 'El ZIP contiene carpetas. Vuelve a comprimirlo seleccionando los archivos, no la carpeta que los contiene.',
                ]);
            }

            $descomprimido += (int) $estadisticas['size'];
        }

        if ($descomprimido > self::MAXIMO_DESCOMPRIMIDO) {
            throw ValidationException::withMessages([
                'archivo' => 'El contenido del ZIP supera los 400 MB descomprimido.',
            ]);
        }
    }

    /**
     * Índice `modelo normalizado => [ids]` de los artículos del catálogo.
     *
     * Se arma una sola vez por petición: emparejar archivo por archivo con una consulta cada uno
     * sería una consulta por foto, y una tanda son veinte.
     *
     * @return array<string, array<int, int>>
     */
    private function modelosDelCatalogo(Catalogo $catalogo): array
    {
        $indice = [];

        foreach ($catalogo->articulos()->get(['id', 'modelo']) as $articulo) {
            $indice[$this->normalizar((string) $articulo->modelo)][] = (int) $articulo->id;
        }

        return $indice;
    }

    /**
     * Forma con la que se comparan el nombre del archivo y el modelo del artículo.
     *
     * Minúsculas, sin acentos, y con toda corrida de espacios, guiones y guiones bajos colapsada a
     * un solo espacio: `a 1234.JPG`, `A-1234.jpg` y `A_1234.jpeg` encuentran al mismo artículo. Lo
     * que se escribe en una hoja de cálculo y lo que un proveedor pone en el nombre de un archivo
     * rara vez coinciden carácter por carácter, y esas tres diferencias son las que aparecen
     * siempre.
     */
    private function normalizar(string $texto): string
    {
        $ascii = Str::lower(Str::ascii($texto));

        return trim((string) preg_replace('/[\s_-]+/', ' ', $ascii));
    }

    /**
     * Borra las rutas temporales que dejó `extraerZip`.
     *
     * @param  array<int, array{0: string, 1: string}>  $archivos
     */
    public function limpiarTemporales(array $archivos): void
    {
        foreach ($archivos as [, $ruta]) {
            try {
                @unlink($ruta);
            } catch (Throwable) {
                // Un temporal que no se pudo borrar no debe tumbar una carga que ya terminó bien.
            }
        }
    }
}
