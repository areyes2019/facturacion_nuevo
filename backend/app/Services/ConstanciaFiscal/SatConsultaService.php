<?php

namespace App\Services\ConstanciaFiscal;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Consulta a la cédula de identificación fiscal del SAT a partir de la dirección codificada en el
 * QR de la constancia (ver 016-constancia-situacion-fiscal-qr.md).
 *
 * La infraestructura del SAT se cae y entra en mantenimiento con frecuencia, así que este servicio
 * está construido para rendirse rápido y no volver a intentarlo por un rato: quien depende de él
 * siempre tiene una Estrategia B lista.
 */
class SatConsultaService
{
    /**
     * Se espera poco a propósito. Insistir tendría sentido si fallar significara "captura todo a
     * mano"; no lo tiene cuando ya hay una alternativa lista y cada segundo es rueda girando.
     */
    private const SEGUNDOS_ESPERA = 5;

    /**
     * El SAT no se cae para una persona: se cae para todos y dura un rato. Tras una falla, las
     * constancias siguientes van directo a la Estrategia B sin pagar la espera.
     */
    private const SEGUNDOS_CIRCUITO_ABIERTO = 120;

    private const SEGUNDOS_CACHE = 86400;

    private const CLAVE_CIRCUITO = 'constancia-fiscal:sat-caido';

    public function __construct(private readonly SatHtmlExtractor $extractor) {}

    /**
     * Solo se aceptan direcciones del dominio oficial, y solo por HTTPS. Es lo que impide que un QR
     * falso —pegado encima del real en una constancia manipulada— dirija al servidor a hacer
     * peticiones a donde el atacante quiera.
     */
    public function urlEsOficial(string $url): bool
    {
        $partes = parse_url($url);

        if (! is_array($partes) || ($partes['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($partes['host'] ?? ''));

        // La comparación es contra el dominio completo y con punto delante en los subdominios, para
        // que "sat.gob.mx.evil.com" y "malsat.gob.mx" no pasen.
        return $host === 'sat.gob.mx' || str_ends_with($host, '.sat.gob.mx');
    }

    /**
     * `false` mientras el circuito está abierto por una falla reciente.
     */
    public function disponible(): bool
    {
        return ! Cache::has(self::CLAVE_CIRCUITO);
    }

    /**
     * Datos oficiales del contribuyente, o `null` si el SAT no dijo nada aprovechable.
     *
     * El circuito se abre solo cuando el SAT **no contesta** —tiempo agotado, error de red, código
     * de error— o cuando contesta sin decir absolutamente nada del contribuyente, que es lo que se
     * ve durante una ventana de mantenimiento. Una respuesta a la que le falte un campo **no** es
     * una caída: es un fallo de lectura nuestro, y su remedio es agregar un alias, no dejar de
     * preguntar. Confundir las dos cosas apagaría la consulta oficial de forma permanente, porque
     * un fallo de lectura se repite en todas las constancias.
     */
    public function consultar(string $url, ?IdentidadQr $identidad = null): ?CamposConstancia
    {
        $enCache = Cache::get($this->claveCache($url));

        if ($enCache instanceof CamposConstancia) {
            return $enCache;
        }

        try {
            $respuesta = Http::timeout(self::SEGUNDOS_ESPERA)
                ->withHeaders(['Accept' => 'text/html'])
                ->get($url);

            if (! $respuesta->successful()) {
                return $this->abrirCircuito();
            }
        } catch (Throwable) {
            return $this->abrirCircuito();
        }

        $campos = $this->extractor->extraer($respuesta->body(), $identidad);

        if (! $campos->traeDatosDelContribuyente()) {
            return $this->abrirCircuito();
        }

        // Solo se cachean los aciertos. Los fallos tienen su propio vencimiento, más corto, en el
        // circuito abierto: cachearlos 24 horas dejaría la función rota mucho después de que el
        // SAT ya revivió.
        Cache::put($this->claveCache($url), $campos, self::SEGUNDOS_CACHE);

        return $campos;
    }

    private function abrirCircuito(): null
    {
        Cache::put(self::CLAVE_CIRCUITO, true, self::SEGUNDOS_CIRCUITO_ABIERTO);

        return null;
    }

    private function claveCache(string $url): string
    {
        return 'constancia-fiscal:sat:'.sha1($url);
    }
}
