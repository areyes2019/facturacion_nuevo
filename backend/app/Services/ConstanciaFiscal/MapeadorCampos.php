<?php

namespace App\Services\ConstanciaFiscal;

use PhpCfdi\Rfc\Rfc;
use PhpCfdi\SatCatalogos\SatCatalogos;

/**
 * Traduce los pares "etiqueta: valor" de una constancia a los campos que espera el formulario de
 * cliente de 004, validando contra los catálogos oficiales del SAT (ver
 * 016-constancia-situacion-fiscal-qr.md).
 *
 * Lo comparten el extractor del HTML del SAT y el del texto del PDF: los dos llegan a un mapa de
 * etiquetas y de ahí en adelante las reglas son idénticas, así que un cambio de criterio (cómo se
 * arma el domicilio, qué se hace con un CP fuera de catálogo) se hace en un solo lugar.
 */
class MapeadorCampos
{
    /**
     * Nombres con que cada dato puede aparecer etiquetado. El SAT ha cambiado la redacción de sus
     * etiquetas entre versiones de la constancia y entre la cédula y el PDF, así que se buscan
     * todas las variantes conocidas en vez de una sola exacta.
     *
     * @var array<string, list<string>>
     */
    private const ALIAS = [
        // En la página del validador el RFC no es un par etiqueta/valor sino parte de una frase:
        // "El RFC: XXX, tiene asociada la siguiente información".
        'rfc' => ['rfc', 'elrfc'],
        'razon_social' => [
            'denominacionorazonsocial', 'denominacionrazonsocial', 'razonsocial',
            'denominacionsocial', 'denominacion', 'nombredelcontribuyente',
        ],
        'nombre' => ['nombres', 'nombre'],
        'apellido_paterno' => ['primerapellido', 'apellidopaterno'],
        'apellido_materno' => ['segundoapellido', 'apellidomaterno'],
        'regimen' => ['regimen', 'regimenfiscal', 'regimenes', 'regimenesfiscales'],
        'codigo_postal' => ['codigopostal', 'cp'],
        'vialidad' => ['nombredevialidad', 'nombredelavialidad', 'vialidad', 'calle'],
        'numero_exterior' => ['numeroexterior', 'numexterior', 'noexterior', 'numeroexteriorb'],
        'numero_interior' => ['numerointerior', 'numinterior', 'nointerior'],
        'colonia' => ['nombredelacolonia', 'colonia'],
        'municipio' => [
            'nombredelmunicipioodemarcacionterritorial', 'municipioodelegacion',
            'municipiododemarcacionterritorial', 'municipio', 'demarcacionterritorial',
        ],
        'estado' => ['nombredelaentidadfederativa', 'entidadfederativa', 'estado'],
    ];

    /** Límite de `clientes.direccion_comercial` (ver la migración de 004). */
    private const LARGO_DIRECCION = 255;

    /**
     * Catálogo `c_RegimenFiscal` de CFDI 4.0. Se enumera porque ni el SAT ni la constancia impresa
     * publican el código: los dos escriben la descripción, y hay que recorrer el catálogo para
     * encontrar de cuál se trata.
     */
    private const CODIGOS_REGIMEN = [
        '601', '603', '605', '606', '607', '608', '610', '611', '612',
        '614', '615', '616', '620', '621', '622', '623', '624', '625', '626',
    ];

    public function __construct(private readonly SatCatalogos $satCatalogos) {}

    /**
     * @param  array<string, string>  $pares  Etiqueta normalizada => valor.
     * @param  list<string>  $textosRegimen  Regímenes hallados en una tabla propia, si los hubo.
     * @param  ?IdentidadQr  $identidad  Contribuyente identificado por el QR, cuando se pudo leer.
     */
    public function mapear(array $pares, array $textosRegimen = [], ?IdentidadQr $identidad = null): CamposConstancia
    {
        $advertencias = [];

        $rfc = $this->rfc($pares, $identidad, $advertencias);
        $razonSocial = $this->razonSocial($pares);
        $codigoPostal = $this->codigoPostal($pares, $advertencias);
        $regimenes = $this->regimenes($pares, $textosRegimen, $advertencias);
        $direccion = $this->direccion($pares);

        if ($razonSocial === null) {
            $advertencias[] = 'No se encontró el nombre o la razón social en la constancia; captúralo a mano.';
        }

        if ($regimenes === []) {
            $advertencias[] = 'No se encontró el régimen fiscal en la constancia; selecciónalo a mano.';
        }

        return new CamposConstancia(
            rfc: $rfc,
            razonSocial: $razonSocial,
            regimenFiscal: $regimenes[0]['id'] ?? null,
            regimenesDisponibles: $regimenes,
            codigoPostalFiscal: $codigoPostal,
            direccionComercial: $direccion,
            advertencias: array_values($advertencias),
        );
    }

    /**
     * Etiqueta reducida a su esqueleto comparable: sin acentos, sin mayúsculas, sin espacios ni
     * puntuación. Así "Denominación o Razón Social:" y "DENOMINACION/RAZON SOCIAL" son la misma
     * llave y no hace falta anticipar cada variante de puntuación del SAT.
     */
    public static function normalizarEtiqueta(string $etiqueta): string
    {
        $sinAcentos = strtr(mb_strtolower(trim(self::aUtf8($etiqueta)), 'UTF-8'), [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);

        return (string) preg_replace('/[^a-z0-9]/', '', $sinAcentos);
    }

    /**
     * El texto de un PDF puede venir en Windows-1252 en vez de UTF-8, y entonces cada acento es un
     * byte suelto que `mb_strtolower` no sabe tratar: "Denominación" acabaría normalizada sin la
     * "o" y ningún alias coincidiría. Se corrige aquí, en el único punto por el que pasan todas las
     * etiquetas.
     */
    public static function aUtf8(string $texto): string
    {
        return mb_check_encoding($texto, 'UTF-8')
            ? $texto
            : mb_convert_encoding($texto, 'UTF-8', 'Windows-1252');
    }

    /**
     * @param  array<string, string>  $pares
     */
    private function buscar(array $pares, string $campo): ?string
    {
        foreach (self::ALIAS[$campo] as $alias) {
            $valor = trim($pares[$alias] ?? '');

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Manda el RFC del QR cuando lo hay: viene codificado para que lo lea una máquina, así que no
     * hay forma de que traiga una letra confundida. El del documento solo se usa cuando no hubo QR
     * que leer, y si los dos discrepan se avisa en vez de elegir en silencio.
     *
     * @param  array<string, string>  $pares
     * @param  list<string>  $advertencias
     */
    private function rfc(array $pares, ?IdentidadQr $identidad, array &$advertencias): ?string
    {
        $documento = $this->rfcDelDocumento($pares);

        if ($identidad === null) {
            return $documento;
        }

        if ($documento !== null && $documento !== $identidad->rfc) {
            $advertencias[] = "El RFC impreso ({$documento}) no coincide con el del código QR ({$identidad->rfc}); se tomó el del código QR.";
        }

        return $identidad->rfc;
    }

    /**
     * @param  array<string, string>  $pares
     */
    private function rfcDelDocumento(array $pares): ?string
    {
        $valor = $this->buscar($pares, 'rfc');

        if ($valor === null) {
            return null;
        }

        // El valor puede traer cola —"XXX, tiene asociada la siguiente información"— porque en la
        // página del SAT el RFC vive dentro de una frase. Se busca el RFC dentro del texto en vez
        // de limpiar el texto entero y confiar en que no quede nada más.
        if (preg_match('/[A-ZÑ&]{3,4}\d{6}[A-Z\d]{3}/u', mb_strtoupper($valor, 'UTF-8'), $coincidencia) !== 1) {
            return null;
        }

        // Un RFC que no parsea no es un dato incompleto sino una extracción fallida: el formulario
        // lo rechazaría con la misma regla `RfcValido` de 004. Se devuelve `null` y el flujo
        // termina en SIN_DATOS, que es más honesto que precargar algo que no se puede guardar.
        return Rfc::parseOrNull($coincidencia[0]) === null ? null : $coincidencia[0];
    }

    /**
     * Persona moral: la denominación tal cual. Persona física: nombre y apellidos unidos en el
     * orden en que los publica el SAT, sin reacomodar ni recapitalizar.
     *
     * @param  array<string, string>  $pares
     */
    private function razonSocial(array $pares): ?string
    {
        if (($denominacion = $this->buscar($pares, 'razon_social')) !== null) {
            return $denominacion;
        }

        $partes = array_filter([
            $this->buscar($pares, 'nombre'),
            $this->buscar($pares, 'apellido_paterno'),
            $this->buscar($pares, 'apellido_materno'),
        ]);

        return $partes === [] ? null : implode(' ', $partes);
    }

    /**
     * @param  array<string, string>  $pares
     * @param  list<string>  $advertencias
     */
    private function codigoPostal(array $pares, array &$advertencias): ?string
    {
        $valor = $this->buscar($pares, 'codigo_postal');

        if ($valor === null) {
            $advertencias[] = 'No se encontró el código postal en la constancia; captúralo a mano.';

            return null;
        }

        $cp = (string) preg_replace('/\D/', '', $valor);

        if (strlen($cp) !== 5 || ! $this->satCatalogos->codigosPostales40()->exists($cp)) {
            // Precargar un CP que el catálogo no conoce solo conseguiría que el guardado fallara
            // con un error de validación que el usuario no sabría explicarse.
            $advertencias[] = "El código postal {$cp} de la constancia no existe en el catálogo del SAT; captúralo a mano.";

            return null;
        }

        return $cp;
    }

    /**
     * @param  array<string, string>  $pares
     * @param  list<string>  $textosRegimen
     * @param  list<string>  $advertencias
     * @return list<array{id: string, texto: string}>
     */
    private function regimenes(array $pares, array $textosRegimen, array &$advertencias): array
    {
        if ($textosRegimen === [] && ($valor = $this->buscar($pares, 'regimen')) !== null) {
            $textosRegimen = [$valor];
        }

        $codigos = [];

        foreach ($textosRegimen as $texto) {
            $codigo = $this->codigoRegimen($texto);

            if ($codigo !== null && ! in_array($codigo, $codigos, true)) {
                $codigos[] = $codigo;
            }
        }

        $regimenes = array_map(fn (string $codigo): array => [
            'id' => $codigo,
            'texto' => $this->satCatalogos->regimenesFiscales40()->obtain($codigo)->texto(),
        ], $codigos);

        if (count($regimenes) > 1) {
            $advertencias[] = 'Este contribuyente tiene más de un régimen fiscal vigente. Se propuso el primero: confirma cuál corresponde.';
        }

        return $regimenes;
    }

    /**
     * El código del catálogo que corresponde a un régimen escrito en palabras.
     *
     * Ni la página del SAT ni la constancia impresa publican el número: las dos escriben la
     * descripción, y encima con un "Régimen de las…" delante que el catálogo no lleva. Se comparan
     * las dos reducidas a su esqueleto —sin acentos, sin mayúsculas y sin espacios—, así que ese
     * prefijo deja de estorbar sin tener que recortarlo a mano.
     *
     * Si el texto trajera el código, también se acepta: es más directo y no cuesta nada admitirlo.
     */
    private function codigoRegimen(string $texto): ?string
    {
        $catalogo = $this->satCatalogos->regimenesFiscales40();

        if (preg_match('/\b(\d{3})\b/', $texto, $coincidencia) === 1 && $catalogo->exists($coincidencia[1])) {
            return $coincidencia[1];
        }

        $buscado = self::normalizarEtiqueta($texto);

        if ($buscado === '') {
            return null;
        }

        foreach (self::CODIGOS_REGIMEN as $codigo) {
            if (! $catalogo->exists($codigo)) {
                continue;
            }

            if (str_contains($buscado, self::normalizarEtiqueta($catalogo->obtain($codigo)->texto()))) {
                return $codigo;
            }
        }

        return null;
    }

    /**
     * El domicilio del SAT viene desarmado en seis campos y el sistema lo guarda en una sola línea
     * (`direccion_comercial`, ver 004). Los componentes vacíos se omiten sin dejar comas sueltas.
     *
     * @param  array<string, string>  $pares
     */
    private function direccion(array $pares): ?string
    {
        $calle = trim(implode(' ', array_filter([
            $this->buscar($pares, 'vialidad'),
            $this->buscar($pares, 'numero_exterior'),
            ($interior = $this->buscar($pares, 'numero_interior')) !== null ? "INT {$interior}" : null,
        ])));

        $colonia = $this->buscar($pares, 'colonia');

        $partes = array_filter([
            $calle === '' ? null : $calle,
            $colonia !== null ? "COL {$colonia}" : null,
            $this->buscar($pares, 'municipio'),
            $this->buscar($pares, 'estado'),
        ]);

        if ($partes === []) {
            return null;
        }

        return mb_substr(implode(', ', $partes), 0, self::LARGO_DIRECCION);
    }
}
