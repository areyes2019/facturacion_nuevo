<?php

namespace App\Http\Controllers;

use App\Enums\MetodoPago;
use App\Enums\MotivoCancelacion;
use App\Enums\ObjetoImpuesto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PhpCfdi\SatCatalogos\SatCatalogos;

class CatalogoController extends Controller
{
    public function __construct(private readonly SatCatalogos $satCatalogos) {}

    /**
     * Catálogo SAT c_RegimenFiscal (CFDI 4.0). Es un catálogo cerrado y pequeño (19 entradas),
     * se devuelve completo para poblar un <select> en el frontend.
     */
    public function regimenesFiscales(): JsonResponse
    {
        $entradas = $this->satCatalogos->regimenesFiscales40()->searchByText('%');

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->texto(),
        ], $entradas);

        usort($data, fn ($a, $b) => $a['id'] <=> $b['id']);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_CodigoPostal (CFDI 4.0). Es demasiado extenso (~96,000 entradas) para
     * cargarlo completo; se busca por prefijo del código.
     */
    public function codigosPostales(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:5'],
        ]);

        $busqueda = preg_replace('/\D/', '', (string) $request->string('q'));

        $entradas = $this->satCatalogos->codigosPostales40()->searchByField('id', $busqueda.'%', 20);

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->id(),
        ], $entradas);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_ClaveProdServ (CFDI 4.0). Es demasiado extenso (~53,000 entradas) para
     * cargarlo completo; se busca por texto.
     */
    public function clavesProdServ(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $entradas = $this->satCatalogos->productosServicios40()->searchByText('%'.$request->string('q')->trim().'%', 20);

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->texto(),
        ], $entradas);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_ClaveUnidad (CFDI 4.0). Es demasiado extenso (~2,400 entradas) para
     * cargarlo completo; se busca por texto.
     */
    public function clavesUnidad(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $entradas = $this->satCatalogos->clavesUnidades40()->searchByText('%'.$request->string('q')->trim().'%', 20);

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->texto(),
        ], $entradas);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_ObjetoImp (CFDI 4.0). Solo 4 valores fijos, hardcodeados como enum en vez
     * de vivir en la base sqlite de catálogos (ver App\Enums\ObjetoImpuesto).
     */
    public function objetosImpuesto(): JsonResponse
    {
        return response()->json(['data' => ObjetoImpuesto::opciones()]);
    }

    /**
     * Catálogo SAT c_UsoCFDI (CFDI 4.0). Se captura por factura (no en el cliente, ver
     * 004-gestion-clientes.md); se busca por texto igual que clave de producto/servicio.
     */
    public function usosCfdi(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $entradas = $this->satCatalogos->usosCfdi40()->searchByText('%'.$request->string('q')->trim().'%', 20);

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->texto(),
        ], $entradas);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_FormaPago (CFDI 4.0). Es un catálogo cerrado y pequeño, se devuelve
     * completo para poblar un <select>/combobox en el frontend.
     */
    public function formasPago(): JsonResponse
    {
        $entradas = $this->satCatalogos->formasDePago40()->searchByText('%');

        $data = array_map(fn ($entrada) => [
            'id' => $entrada->id(),
            'texto' => $entrada->texto(),
        ], $entradas);

        usort($data, fn ($a, $b) => $a['id'] <=> $b['id']);

        return response()->json(['data' => $data]);
    }

    /**
     * Catálogo SAT c_MotivoCancelacion (CFDI 4.0). Solo 4 valores fijos, hardcodeados como enum
     * en vez de vivir en la base sqlite de catálogos (ver App\Enums\MotivoCancelacion).
     */
    public function motivosCancelacion(): JsonResponse
    {
        return response()->json(['data' => MotivoCancelacion::opciones()]);
    }

    /**
     * Método de pago CFDI (PUE/PPD). Solo 2 valores fijos, hardcodeados como enum (ver
     * App\Enums\MetodoPago).
     */
    public function metodosPago(): JsonResponse
    {
        return response()->json(['data' => MetodoPago::opciones()]);
    }
}
