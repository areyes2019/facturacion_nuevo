<?php

namespace App\Services\ConstanciaFiscal;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Extrae los campos fiscales del HTML que devuelve la cédula de identificación del SAT (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * No se atan selectores CSS a la maquetación concreta de esa página: se recogen todos los pares
 * "etiqueta: valor" que haya —en tablas, en listas de definición o en texto corrido— y luego se
 * buscan por nombre. Un rediseño del SAT que mueva las cajas de lugar pero conserve las etiquetas
 * sigue funcionando; uno que renombre las etiquetas se atrapa con los alias del mapeador.
 */
class SatHtmlExtractor
{
    /**
     * Etiquetas de dos caracteres, como el `CP:` del validador. Lo que impide que una etiqueta
     * corta traiga basura no es su longitud sino que solo se usan las que están en la lista de
     * alias del mapeador.
     */
    private const LARGO_MINIMO_ETIQUETA = 2;

    public function __construct(private readonly MapeadorCampos $mapeador) {}

    public function extraer(string $html, ?IdentidadQr $identidad = null): CamposConstancia
    {
        try {
            $crawler = $this->crawler($html);
            $pares = [...$this->paresDeTexto($html), ...$this->paresDeTablas($crawler)];
            $textosRegimen = $this->textosRegimen($crawler);
        } catch (Throwable) {
            return new CamposConstancia;
        }

        return $this->mapeador->mapear($pares, $textosRegimen, $identidad);
    }

    /**
     * La página del validador antepone una cabecera `<?xml …?>` al `<!DOCTYPE html>`, así que hay
     * que decirle explícitamente que la lea como HTML: si se deja adivinar, la toma por XML, exige
     * que cada etiqueta viva en su espacio de nombres y **ningún `<tr>` coincide**. No falla de
     * forma ruidosa —simplemente devuelve cero filas— y todo el trabajo acaba recayendo en el
     * respaldo de texto plano, que ve bastante menos.
     */
    private function crawler(string $html): Crawler
    {
        $crawler = new Crawler;
        $crawler->addHtmlContent($html);

        return $crawler;
    }

    /**
     * Filas de dos celdas: la primera es la etiqueta y la segunda el valor. Cubre la maquetación
     * habitual de la cédula.
     *
     * @return array<string, string>
     */
    private function paresDeTablas(Crawler $crawler): array
    {
        $pares = [];

        foreach ($this->filasDeDosCeldas($crawler) as [$etiqueta, $valor]) {
            if ($etiqueta !== '' && $valor !== '' && ! isset($pares[$etiqueta])) {
                $pares[$etiqueta] = $valor;
            }
        }

        return $pares;
    }

    /**
     * Respaldo para las páginas donde el dato no vive en una tabla sino en texto corrido con la
     * forma "Etiqueta: valor". Se aplica primero para que los pares de tabla, más confiables, lo
     * sobrescriban.
     *
     * @return array<string, string>
     */
    private function paresDeTexto(string $html): array
    {
        $texto = html_entity_decode(strip_tags(preg_replace('/<(br|\/tr|\/p|\/div|\/li)[^>]*>/i', "\n", $html) ?? $html));

        // Los espacios alrededor de los dos puntos son horizontales a propósito: con `\s` la
        // expresión se come el salto de línea y una etiqueta sin valor ("Número Interior:") se
        // queda con el contenido del renglón siguiente.
        preg_match_all(
            '/^[ \t\x{00A0}]*([^\r\n:]{'.self::LARGO_MINIMO_ETIQUETA.',80}?)[ \t]*:[ \t]*([^\r\n]{1,120}?)[ \t\x{00A0}]*$/mu',
            $texto,
            $coincidencias,
            PREG_SET_ORDER,
        );

        $pares = [];

        foreach ($coincidencias as [, $etiqueta, $valor]) {
            $etiqueta = MapeadorCampos::normalizarEtiqueta($etiqueta);

            if ($etiqueta !== '' && ! isset($pares[$etiqueta])) {
                $pares[$etiqueta] = trim($valor);
            }
        }

        return $pares;
    }

    /**
     * Un contribuyente puede tener varios regímenes vigentes, y el SAT los publica como **filas
     * repetidas con la misma etiqueta**. Hay que recogerlas todas: quedarse con la primera
     * aparición de cada etiqueta —que es lo correcto para el resto de los campos— aquí perdería
     * todos los regímenes menos uno.
     *
     * Se devuelven los textos tal cual, porque el catálogo no se busca por número: ni esta página
     * ni la constancia impresa publican el código.
     *
     * @return list<string>
     */
    private function textosRegimen(Crawler $crawler): array
    {
        $textos = [];

        foreach ($this->filasDeDosCeldas($crawler) as [$etiqueta, $valor]) {
            if (str_starts_with($etiqueta, 'regimen') && $valor !== '') {
                $textos[] = $valor;
            }
        }

        // Cuando en vez de pares hay una tabla de regímenes, el dato es la primera celda de cada
        // fila y el encabezado es lo único que dice de qué tabla se trata.
        $crawler->filter('table')->each(function (Crawler $tabla) use (&$textos): void {
            $encabezados = implode(' ', $tabla->filter('th')->each(fn (Crawler $th) => $th->text('')));

            if (! str_contains(MapeadorCampos::normalizarEtiqueta($encabezados), 'regimen')) {
                return;
            }

            $tabla->filter('tr')->each(function (Crawler $fila) use (&$textos): void {
                $primera = $fila->filter('td')->first();

                if ($primera->count() > 0 && trim($primera->text('')) !== '') {
                    $textos[] = trim($primera->text(''));
                }
            });
        });

        return array_values(array_unique($textos));
    }

    /**
     * @return list<array{0: string, 1: string}> Etiqueta normalizada y valor, en el orden del HTML.
     */
    private function filasDeDosCeldas(Crawler $crawler): array
    {
        $filas = [];

        $crawler->filter('tr')->each(function (Crawler $fila) use (&$filas): void {
            $celdas = $fila->filter('td, th');

            if ($celdas->count() !== 2) {
                return;
            }

            $filas[] = [
                MapeadorCampos::normalizarEtiqueta($celdas->eq(0)->text('')),
                trim($celdas->eq(1)->text('')),
            ];
        });

        return $filas;
    }
}
