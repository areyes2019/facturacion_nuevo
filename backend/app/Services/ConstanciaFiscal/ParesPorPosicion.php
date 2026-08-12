<?php

namespace App\Services\ConstanciaFiscal;

/**
 * Reconstruye los pares "etiqueta: valor" de una constancia en PDF a partir de la **posición** de
 * cada trozo de texto (ver 016-constancia-situacion-fiscal-qr.md).
 *
 * Un PDF no guarda renglones ni palabras: guarda trozos de letras con la coordenada donde va cada
 * uno. El texto "de corrido" que devuelve cualquier librería es ya una interpretación de esas
 * coordenadas, y en la constancia del SAT se equivoca de dos maneras a la vez: pierde los espacios
 * dentro de un valor —"CIUDAD OLMECA" sale como "CIUDADOLMECA"— y junta en un solo renglón las dos
 * columnas en que está acomodado el domicilio.
 *
 * Aquí se trabaja con las coordenadas directamente, que es lo único que permite distinguir un
 * espacio de un salto a la otra columna: los dos son "un hueco", y solo el tamaño los separa.
 *
 * La clase no sabe nada de PDF ni de constancias: recibe trozos con su posición y devuelve pares.
 * Así se puede probar con las coordenadas exactas de un documento real sin cargar ningún archivo.
 */
class ParesPorPosicion
{
    /**
     * Ancho de carácter deliberadamente **sobreestimado**. Solo sirve para saber dónde termina más
     * o menos un trozo, y equivocarse por exceso es el error seguro: acerca los trozos entre sí y
     * los deja en la misma celda. Pasarse por defecto partiría un valor en dos.
     */
    private const ANCHO_CARACTER = 7.0;

    /**
     * A partir de aquí el hueco ya no es un espacio sino el salto a la otra columna. En una
     * constancia real los espacios entre palabras miden unas pocas unidades y el salto de columna
     * ronda las 180: el margen es enorme y el umbral exacto no es delicado.
     */
    private const HUECO_CELDA = 30.0;

    /** Dos trozos del mismo renglón pueden diferir en unas centésimas de altura. */
    private const TOLERANCIA_RENGLON = 2.0;

    /** Distancia máxima entre dos renglones para que el segundo continúe al primero. */
    private const ALTURA_RENGLON = 14.0;

    /** Cuánto puede moverse el inicio de una celda y seguir siendo la misma columna. */
    private const TOLERANCIA_COLUMNA = 5.0;

    /**
     * Lo que se pudo leer del documento: los pares "etiqueta: valor", y aparte las celdas que no
     * llevan etiqueta ninguna.
     *
     * Las sueltas importan porque no todo en una constancia viene etiquetado: los regímenes son una
     * tabla donde cada renglón es la descripción a secas. Se devuelven en crudo y quien las use
     * decide qué son.
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $trozos  Cada uno como `[x, y, texto]`.
     * @return array{pares: array<string, string>, sueltas: list<string>}
     */
    public function analizar(array $trozos): array
    {
        $pares = [];
        $sueltas = [];

        // Última celda con valor de cada columna, para saber a quién continúa un renglón suelto.
        $ultimaPorColumna = [];

        foreach ($this->renglones($trozos) as ['y' => $y, 'celdas' => $celdas]) {
            $total = count($celdas);

            for ($i = 0; $i < $total; $i++) {
                ['x' => $x, 'texto' => $texto] = $celdas[$i];
                $columna = (int) round($x / self::TOLERANCIA_COLUMNA);

                if (! str_contains($texto, ':')) {
                    if (! $this->continuar($pares, $ultimaPorColumna, $columna, $y, $texto)) {
                        $sueltas[] = $texto;
                    }

                    continue;
                }

                [$etiqueta, $valor] = explode(':', $texto, 2);
                $valor = trim($valor);

                // En la tabla de identificación la etiqueta y su valor son dos columnas distintas
                // ("RFC:" a la izquierda, el RFC a la derecha), así que caen en celdas separadas. Si
                // la celda de al lado no trae dos puntos, es el valor de esta.
                if ($valor === '' && isset($celdas[$i + 1]) && ! str_contains($celdas[$i + 1]['texto'], ':')) {
                    $valor = trim($celdas[$i + 1]['texto']);
                    $i++;
                }

                $clave = MapeadorCampos::normalizarEtiqueta($etiqueta);

                if ($clave === '') {
                    continue;
                }

                if (! isset($pares[$clave])) {
                    $pares[$clave] = $valor;
                }

                if ($valor !== '') {
                    $ultimaPorColumna[$columna] = ['clave' => $clave, 'y' => $y];
                }
            }
        }

        return [
            'pares' => array_filter($pares, static fn (string $valor): bool => $valor !== ''),
            'sueltas' => $sueltas,
        ];
    }

    /**
     * Un renglón sin dos puntos justo debajo de un valor, y en su misma columna, es la
     * continuación de ese valor: es el caso de "VERACRUZ DE IGNACIO DE LA" separado de su "LLAVE"
     * por el ancho de la caja.
     *
     * Se exige que esté pegado —el renglón inmediatamente siguiente— para que un pie de página o
     * un encabezado suelto no se cuelen al final de un domicilio.
     *
     * @param  array<string, string>  $pares
     * @param  array<int, array{clave: string, y: float}>  $ultimaPorColumna
     * @return bool `false` si no continuaba nada, y entonces la celda queda suelta.
     */
    private function continuar(array &$pares, array $ultimaPorColumna, int $columna, float $y, string $texto): bool
    {
        $anterior = $ultimaPorColumna[$columna] ?? null;

        if ($anterior === null || $anterior['y'] - $y > self::ALTURA_RENGLON) {
            return false;
        }

        $pares[$anterior['clave']] = trim($pares[$anterior['clave']].' '.$texto);

        return true;
    }

    /**
     * Agrupa los trozos en renglones —de arriba abajo— y cada renglón en celdas.
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $trozos
     * @return list<array{y: float, celdas: list<array{x: float, texto: string}>}>
     */
    private function renglones(array $trozos): array
    {
        usort($trozos, static fn (array $a, array $b): int => $b[1] <=> $a[1] ?: $a[0] <=> $b[0]);

        $renglones = [];
        $actual = [];
        $y = 0.0;

        foreach ($trozos as $trozo) {
            if ($actual !== [] && abs($y - $trozo[1]) > self::TOLERANCIA_RENGLON) {
                $renglones[] = ['y' => $y, 'celdas' => $this->celdas($actual)];
                $actual = [];
            }

            if ($actual === []) {
                $y = $trozo[1];
            }

            $actual[] = $trozo;
        }

        if ($actual !== []) {
            $renglones[] = ['y' => $y, 'celdas' => $this->celdas($actual)];
        }

        return $renglones;
    }

    /**
     * Une los trozos de un renglón: con un espacio —el que el PDF no guardó— mientras el hueco sea
     * de palabra, y abriendo una celda nueva cuando el hueco es de columna.
     *
     * @param  list<array{0: float, 1: float, 2: string}>  $trozos
     * @return list<array{x: float, texto: string}>
     */
    private function celdas(array $trozos): array
    {
        usort($trozos, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $celdas = [];
        $fin = null;

        foreach ($trozos as [$x, , $texto]) {
            if ($texto === '') {
                continue;
            }

            if ($celdas === [] || ($fin !== null && $x - $fin > self::HUECO_CELDA)) {
                $celdas[] = ['x' => $x, 'texto' => $texto];
            } else {
                $celdas[count($celdas) - 1]['texto'] .= ' '.$texto;
            }

            $fin = $x + mb_strlen($texto) * self::ANCHO_CARACTER;
        }

        return array_map(
            static fn (array $celda): array => ['x' => $celda['x'], 'texto' => trim($celda['texto'])],
            $celdas,
        );
    }
}
