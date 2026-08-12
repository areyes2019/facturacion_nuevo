<?php

namespace App\Services;

use PhpCfdi\SatCatalogos\SatCatalogos;
use Throwable;

/**
 * Descripción legible de una clave del catálogo SAT (ver 019-formato-pdf-documentos.md).
 *
 * El formato de referencia llevaba las descripciones escritas a mano dentro de la plantilla: nueve
 * regímenes de diecinueve y nueve usos de CFDI. Una lista así nace incompleta y se desactualiza
 * sola; aquí se consulta el mismo catálogo que ya alimenta los `<select>` del sistema.
 *
 * Se registra como singleton y memoiza por catálogo y clave: un PDF pregunta por el régimen del
 * emisor y por el del receptor, y sin memoria cada pregunta sería una consulta a sqlite.
 */
class SatDescripciones
{
    /** @var array<string, string|null> */
    private array $memo = [];

    public function __construct(private readonly SatCatalogos $satCatalogos) {}

    /**
     * `612 - Personas Físicas con Actividades Empresariales y Profesionales`, o la clave sola si no
     * está en el catálogo.
     *
     * Una clave desconocida se imprime tal cual, sin error: un código raro en el papel es
     * preferible a un documento que no sale.
     */
    public function regimenFiscal(?string $clave): string
    {
        return $this->conDescripcion('regimen', $clave);
    }

    public function usoCfdi(?string $clave): string
    {
        return $this->conDescripcion('uso', $clave);
    }

    public function formaPago(?string $clave): string
    {
        return $this->conDescripcion('forma-pago', $clave);
    }

    private function conDescripcion(string $catalogo, ?string $clave): string
    {
        if (! filled($clave)) {
            return '';
        }

        $clave = (string) $clave;
        $llave = $catalogo.':'.$clave;

        // `??=` no sirve aquí: una clave que no está en el catálogo memoiza `null` y se volvería a
        // consultar en cada llamada, que es justo el caso que más conviene recordar.
        if (! array_key_exists($llave, $this->memo)) {
            $this->memo[$llave] = $this->buscarSinFallar($catalogo, $clave);
        }

        $texto = $this->memo[$llave];

        return $texto === null ? $clave : $clave.' - '.$texto;
    }

    private function buscarSinFallar(string $catalogo, string $clave): ?string
    {
        try {
            return match ($catalogo) {
                'regimen' => $this->satCatalogos->regimenesFiscales40()->obtain($clave)->texto(),
                'uso' => $this->satCatalogos->usosCfdi40()->obtain($clave)->texto(),
                'forma-pago' => $this->satCatalogos->formasDePago40()->obtain($clave)->texto(),
                default => null,
            };
        } catch (Throwable) {
            return null;
        }
    }
}
