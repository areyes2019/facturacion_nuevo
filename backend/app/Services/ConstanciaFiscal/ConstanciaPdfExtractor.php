<?php

namespace App\Services\ConstanciaFiscal;

use Smalot\PdfParser\Page;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Estrategia B para el caso más común: el PDF que el contribuyente descargó del SAT (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * Un PDF de ese tipo trae las letras "adentro", como un documento de texto: se copian carácter por
 * carácter, sin adivinar nada. Es exacto; su único límite es que dice lo que decía el papel el día
 * que se imprimió, no lo que el SAT tiene hoy — de ahí el aviso ámbar en la interfaz.
 *
 * Lo que no se puede copiar son los espacios ni los renglones, porque el PDF no los guarda: guarda
 * cada trozo de texto con la coordenada donde va. Por eso el documento se lee por posición y no de
 * corrido (ver `ParesPorPosicion`).
 *
 * Si el PDF es en realidad un escaneo metido dentro de un PDF, no hay trozos que leer y devuelve
 * `null`: ese caso lo resuelve el reconocimiento de caracteres, que corre en el navegador.
 */
class ConstanciaPdfExtractor
{
    public function __construct(
        private readonly MapeadorCampos $mapeador,
        private readonly ParesPorPosicion $paresPorPosicion,
    ) {}

    /**
     * @param  string  $contenido  Bytes del PDF, leídos en memoria; nunca se escribe en disco.
     */
    public function extraer(string $contenido, ?IdentidadQr $identidad = null): ?CamposConstancia
    {
        try {
            $paginas = (new Parser)->parseContent($contenido)->getPages();
        } catch (Throwable) {
            return null;
        }

        $pares = [];
        $sueltas = [];
        $huboTexto = false;

        // Cada página se lee por separado porque las coordenadas empiezan de cero en todas: unir
        // los trozos de dos páginas mezclaría renglones que no tienen nada que ver. De la unión de
        // los pares se queda la primera aparición, como en el resto del sistema.
        foreach ($paginas as $pagina) {
            $trozos = $this->trozos($pagina);

            if ($trozos === []) {
                continue;
            }

            $huboTexto = true;
            ['pares' => $nuevos, 'sueltas' => $nuevasSueltas] = $this->paresPorPosicion->analizar($trozos);

            $pares += $nuevos;
            $sueltas = [...$sueltas, ...$nuevasSueltas];
        }

        if (! $huboTexto) {
            return null;
        }

        return $this->mapeador->mapear($pares, $this->textosRegimen($sueltas), $identidad);
    }

    /**
     * Los trozos de texto de una página, cada uno con la coordenada donde el PDF lo coloca.
     *
     * @return list<array{0: float, 1: float, 2: string}>
     */
    private function trozos(Page $pagina): array
    {
        try {
            $datos = $pagina->getDataTm();
        } catch (Throwable) {
            return [];
        }

        $trozos = [];

        foreach ($datos as [$tm, $texto]) {
            $texto = trim(MapeadorCampos::aUtf8((string) $texto));

            if ($texto === '' || ! isset($tm[4], $tm[5])) {
                continue;
            }

            $trozos[] = [(float) $tm[4], (float) $tm[5], $texto];
        }

        return $trozos;
    }

    /**
     * En la constancia impresa los regímenes no son un par etiqueta/valor sino una tabla en la que
     * cada renglón empieza con la palabra "Régimen" y sigue con la descripción. Se recogen esos
     * renglones de entre las celdas sin etiqueta, y el mapeador los resuelve contra el catálogo por
     * su descripción, que es lo único que el documento publica.
     *
     * @param  list<string>  $sueltas
     * @return list<string>
     */
    private function textosRegimen(array $sueltas): array
    {
        return array_values(array_unique(array_filter(
            $sueltas,
            static fn (string $texto): bool => preg_match('/^r[eé]gimen\b/iu', $texto) === 1,
        )));
    }
}
