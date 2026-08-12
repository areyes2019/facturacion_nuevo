<?php

namespace App\Services\ConstanciaFiscal;

/**
 * Los datos fiscales extraídos de una constancia, ya validados contra los catálogos del SAT y
 * listos para precargar el formulario de cliente (ver 016-constancia-situacion-fiscal-qr.md).
 *
 * Es el mismo contenedor para los tres caminos de extracción (SAT en vivo, texto del PDF y
 * reconocimiento en el navegador), así que el controlador arma una sola forma de respuesta sin
 * ramificar por origen.
 *
 * Un campo que no se pudo extraer se queda en `null`: es preferible un campo vacío que el usuario
 * llena a un dato inventado que no nota.
 */
readonly class CamposConstancia
{
    /**
     * @param  list<array{id: string, texto: string}>  $regimenesDisponibles
     * @param  list<string>  $advertencias  Textos ya redactados para mostrarse tal cual.
     */
    public function __construct(
        public ?string $rfc = null,
        public ?string $razonSocial = null,
        public ?string $regimenFiscal = null,
        public array $regimenesDisponibles = [],
        public ?string $codigoPostalFiscal = null,
        public ?string $direccionComercial = null,
        public array $advertencias = [],
    ) {}

    /**
     * Sin RFC no hay nada que precargar: es el único campo del que no se puede prescindir, porque
     * es la identidad del cliente y el que dispara la búsqueda de duplicados.
     */
    public function tieneDatos(): bool
    {
        return $this->rfc !== null;
    }

    /**
     * Si la fuente llegó a decir algo **del contribuyente**, más allá de identificarlo.
     *
     * El RFC no cuenta, porque desde que se lee del código QR está disponible aunque la fuente no
     * haya aportado nada: una pantalla de mantenimiento del SAT o un PDF escaneado devolverían un
     * juego de campos con RFC y nada más, y pasarían por buenos. Lo que distingue una respuesta
     * útil es que traiga al menos el nombre o el domicilio.
     */
    public function traeDatosDelContribuyente(): bool
    {
        return $this->razonSocial !== null || $this->direccionComercial !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rfc' => $this->rfc,
            'razon_social' => $this->razonSocial,
            'regimen_fiscal' => $this->regimenFiscal,
            'regimenes_disponibles' => $this->regimenesDisponibles,
            'codigo_postal_fiscal' => $this->codigoPostalFiscal,
            'direccion_comercial' => $this->direccionComercial,
        ];
    }
}
