<?php

namespace App\Services\ConstanciaFiscal;

use PhpCfdi\Rfc\Rfc;

/**
 * El contribuyente identificado por el código QR de su constancia (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * La dirección del QR lleva el idCIF y el RFC juntos en el parámetro `D3`:
 *
 *     …/validadorqr.jsf?D1=10&D2=1&D3=16040688444_OAMN910602UXA
 *
 * Es el dato más confiable de todo el trámite, porque es el único que viene codificado para que lo
 * lea una máquina y no impreso para que lo lea una persona: no hay forma de confundir una `O` con
 * un `0`. Por eso la identidad se toma de aquí y no de la lectura del documento ni de la respuesta
 * del SAT, y por eso el RFC llega correcto aunque el SAT no conteste.
 */
readonly class IdentidadQr
{
    private function __construct(
        public string $idCif,
        public string $rfc,
    ) {}

    /**
     * `null` cuando la dirección no lleva un `D3` con esa forma.
     *
     * Es también lo que distingue el QR bueno del otro: una constancia trae más de un código, y el
     * de la última página codifica la cadena original del sello digital, cuyo `D3` no se parece en
     * nada a esto.
     */
    public static function desdeUrl(string $url): ?self
    {
        $consulta = parse_url($url, PHP_URL_QUERY);

        if (! is_string($consulta)) {
            return null;
        }

        parse_str($consulta, $parametros);

        $d3 = mb_strtoupper(trim((string) ($parametros['D3'] ?? '')), 'UTF-8');

        if (preg_match('/^(\d+)_([A-ZÑ&\d]{12,13})$/u', $d3, $partes) !== 1) {
            return null;
        }

        // Se valida con la misma librería que el resto del sistema: un RFC que no parsea no
        // identifica a nadie, y precargarlo solo conseguiría que el guardado fallara después.
        return Rfc::parseOrNull($partes[2]) === null
            ? null
            : new self($partes[1], $partes[2]);
    }
}
