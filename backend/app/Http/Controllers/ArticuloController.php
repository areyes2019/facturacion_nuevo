<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Enums\TamanoGoma;
use App\Http\Requests\Articulos\EliminarLoteArticulosRequest;
use App\Http\Requests\Articulos\StoreArticuloRequest;
use App\Http\Requests\Articulos\UpdateArticuloRequest;
use App\Http\Resources\ArticuloResource;
use App\Models\Articulo;
use App\Models\Catalogo;
use App\Rules\ClaveProdServValido;
use App\Rules\ClaveUnidadValido;
use App\Rules\ValorDeEnum;
use App\Services\ConfiguracionService;
use App\Services\PrecioArticuloCalculator;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArticuloController extends Controller
{
    /**
     * Columnas compartidas entre la importación y la exportación CSV (ver 006-gestion-articulos.md).
     *
     * `tamano_goma` va al final para que un CSV de 7 columnas de 011 siga siendo importable sin
     * cambios (ver 014-costo-elaboracion-goma.md).
     */
    private const COLUMNAS_CSV = [
        'nombre',
        'modelo',
        'clave_prod_serv',
        'clave_unidad',
        'objeto_imp',
        'precio_proveedor',
        'utilidad_porcentaje',
        'tamano_goma',
    ];

    /**
     * Columnas numéricas ordenables del listado (ver 011-precio-proveedor-utilidad.md). Ni el costo
     * total ni la utilidad están persistidos, así que se ordena por la expresión que los define
     * (ver 014-costo-elaboracion-goma.md).
     */
    private const ORDENACIONES = [
        'costo_total' => 'costo_con_descuento + costo_goma',
        'precio_unitario_sin_iva' => 'precio_unitario_sin_iva',
        'utilidad' => 'precio_unitario_sin_iva - (costo_con_descuento + costo_goma)',
    ];

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $articulos = $this->ordenar($this->filtrarBusqueda($request->user()->articulos(), $request), $request)
            ->with('catalogo.proveedor')
            ->paginate(15)
            ->withQueryString();

        return ArticuloResource::collection($articulos);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreArticuloRequest $request): ArticuloResource
    {
        $datos = $request->validated();
        $catalogo = Catalogo::findOrFail($datos['catalogo_id']);
        $costoGoma = $this->costoGomaDe($request, $datos);

        $cadena = PrecioArticuloCalculator::calcularCadena(
            (float) $datos['precio_proveedor'],
            (float) $catalogo->descuento,
            (float) ($datos['utilidad_porcentaje'] ?? $catalogo->utilidad_porcentaje),
            $costoGoma,
        );

        $articulo = $request->user()->articulos()->create([
            ...$datos,
            'costo_goma' => $costoGoma,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
        ]);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Articulo $articulo): ArticuloResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateArticuloRequest $request, Articulo $articulo): ArticuloResource
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $datos = $request->validated();
        $catalogo = Catalogo::findOrFail($datos['catalogo_id']);
        $costoGoma = $this->costoGomaDe($request, $datos);

        $cadena = PrecioArticuloCalculator::calcularCadena(
            (float) $datos['precio_proveedor'],
            (float) $catalogo->descuento,
            (float) ($datos['utilidad_porcentaje'] ?? $catalogo->utilidad_porcentaje),
            $costoGoma,
        );

        $articulo->update([
            ...$datos,
            'costo_goma' => $costoGoma,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
        ]);

        return new ArticuloResource($articulo->load('catalogo.proveedor'));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Articulo $articulo): Response
    {
        abort_unless($articulo->user_id === $request->user()->id, 404);

        $articulo->delete();

        return response()->noContent();
    }

    /**
     * Borra en lote los artículos seleccionados en el listado (ver
     * 021-mantenimiento-articulos-catalogos.md).
     *
     * Viaja en **una sola petición** con la lista de identificadores, no en una por artículo: con N
     * peticiones, una conexión que se cae a la mitad deja un subconjunto arbitrario borrado y sin
     * forma de saber cuál.
     *
     * La pertenencia de cada `id` al usuario ya la garantizó `EliminarLoteArticulosRequest`; el
     * `whereIn` se aplica igual sobre la relación del usuario, para que el scopeo no dependa de que
     * nadie relaje esa regla más adelante.
     *
     * Borrado lógico, igual que el individual: las imágenes se quedan en disco por si el artículo se
     * restaura (ver 020-imagenes-articulos.md).
     */
    public function eliminarLote(EliminarLoteArticulosRequest $request): JsonResponse
    {
        $ids = $request->validated()['ids'];

        // La transacción es lo que hace real el "todo o nada": si algo falla a media operación, la
        // base de datos deshace lo que llevaba y queda exactamente como estaba.
        $eliminados = DB::transaction(
            fn (): int => $request->user()->articulos()->whereIn('id', $ids)->delete()
        );

        return response()->json(['eliminados' => $eliminados]);
    }

    /**
     * Importa artículos desde un CSV, todos asociados al catálogo de la ruta (y por lo tanto a
     * su proveedor). Las filas válidas se importan y las inválidas se reportan sin abortar el
     * archivo completo.
     */
    public function importarCsv(Request $request, Catalogo $catalogo): JsonResponse
    {
        abort_unless($catalogo->user_id === $request->user()->id, 404);

        $request->validate([
            'archivo' => ['required', 'file', 'mimes:csv,txt'],
        ]);

        // Catálogos del mismo proveedor (para la unicidad de nombre, ver 009-catalogos.md);
        // se calcula una sola vez fuera del bucle porque no cambia entre filas.
        $catalogosDelProveedor = Catalogo::query()->where('proveedor_id', $catalogo->proveedor_id)->pluck('id');

        $handle = $this->abrirCsvComoUtf8($request->file('archivo')->getRealPath());
        $columnas = array_map(fn ($columna) => trim((string) $columna), fgetcsv($handle) ?: []);

        $importados = 0;
        $errores = [];
        $fila = 1;

        while (($registro = fgetcsv($handle)) !== false) {
            $fila++;

            if ($registro === [null] || $registro === false) {
                continue;
            }

            $datos = array_combine($columnas, $registro) ?: [];

            // Celda de porcentaje vacía = hereda el del catálogo destino (ver 011). Se normaliza a
            // null antes de validar para que la regla `nullable` la deje pasar.
            $utilidad = trim((string) ($datos['utilidad_porcentaje'] ?? ''));

            // Celda de tamaño vacía = el artículo no lleva goma (ver 014). Se acepta con cualquier
            // combinación de mayúsculas y espacios alrededor: "Grande ", "GRANDE" y "grande" son la
            // misma categoría.
            $tamano = strtolower(trim((string) ($datos['tamano_goma'] ?? '')));

            $objetoImpuesto = $this->normalizarObjetoImpuesto((string) ($datos['objeto_imp'] ?? ''));

            $validator = Validator::make(
                [
                    'nombre' => trim((string) ($datos['nombre'] ?? '')),
                    'modelo' => trim((string) ($datos['modelo'] ?? '')),
                    'clave_prod_serv' => trim((string) ($datos['clave_prod_serv'] ?? '')),
                    'clave_unidad' => strtoupper(trim((string) ($datos['clave_unidad'] ?? ''))),
                    'objeto_imp' => $objetoImpuesto,
                    'precio_proveedor' => trim((string) ($datos['precio_proveedor'] ?? '')),
                    'utilidad_porcentaje' => $utilidad === '' ? null : $utilidad,
                    'tamano_goma' => $tamano === '' ? null : $tamano,
                ],
                [
                    'nombre' => [
                        'required',
                        'string',
                        'max:255',
                        Rule::unique('articulos', 'nombre')
                            ->whereIn('catalogo_id', $catalogosDelProveedor)
                            ->whereNull('deleted_at'),
                    ],
                    'modelo' => ['required', 'string', 'max:255'],
                    'clave_prod_serv' => ['required', 'string', new ClaveProdServValido],
                    'clave_unidad' => ['required', 'string', new ClaveUnidadValido],
                    'objeto_imp' => ['required', new ValorDeEnum(ObjetoImpuesto::class)],
                    'precio_proveedor' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
                    'utilidad_porcentaje' => ['nullable', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
                    'tamano_goma' => ['nullable', new ValorDeEnum(TamanoGoma::class)],
                ],
            );

            if ($validator->fails()) {
                $errores[] = [
                    'fila' => $fila,
                    'modelo' => $this->identidadDeLaFila($datos),
                    'motivo' => $validator->errors()->first(),
                ];

                continue;
            }

            $filaValidada = $validator->validated();
            $costoGoma = $this->costoGomaDe($request, $filaValidada);

            $cadena = PrecioArticuloCalculator::calcularCadena(
                (float) $filaValidada['precio_proveedor'],
                (float) $catalogo->descuento,
                (float) ($filaValidada['utilidad_porcentaje'] ?? $catalogo->utilidad_porcentaje),
                $costoGoma,
            );

            $request->user()->articulos()->create([
                ...$filaValidada,
                'catalogo_id' => $catalogo->id,
                'costo_goma' => $costoGoma,
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            ]);
            $importados++;
        }

        fclose($handle);

        return response()->json(['importados' => $importados, 'errores' => $errores]);
    }

    /**
     * Con qué nombre se identifica en el reporte una fila que no se pudo importar.
     *
     * Se prefiere `modelo` porque es el campo con el que se emparejan las imágenes (ver 020): es el
     * dato que conecta el renglón rechazado con la foto que se va a quedar sin artículo, y sin él el
     * usuario tiene que abrir el CSV y contar renglones para saber cuál es cuál
     * (ver 023-carga-masiva-por-pasos.md).
     *
     * Va tal como venía en la celda, sin normalizar: si el modelo estaba mal escrito, verlo mal
     * escrito es justamente lo que permite corregirlo.
     *
     * @param  array<string, mixed>  $datos
     */
    private function identidadDeLaFila(array $datos): ?string
    {
        foreach (['modelo', 'nombre'] as $columna) {
            $valor = trim((string) ($datos[$columna] ?? ''));

            if ($valor !== '') {
                return $valor;
            }
        }

        return null;
    }

    /**
     * Exporta a CSV el listado de artículos resultante de la búsqueda aplicada, con las mismas
     * columnas que espera la importación.
     */
    public function exportarCsv(Request $request): StreamedResponse
    {
        $articulos = $this->ordenar($this->filtrarBusqueda($request->user()->articulos(), $request), $request)
            ->get();

        return response()->streamDownload(function () use ($articulos) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::COLUMNAS_CSV);

            foreach ($articulos as $articulo) {
                fputcsv($handle, [
                    $articulo->nombre,
                    $articulo->modelo,
                    $articulo->clave_prod_serv,
                    $articulo->clave_unidad,
                    $articulo->objeto_imp->value,
                    $articulo->precio_proveedor,
                    // Celda vacía cuando el artículo hereda el porcentaje del catálogo, para que el
                    // archivo exportado sea reimportable conservando la herencia (ver 011).
                    $articulo->utilidad_porcentaje,
                    // Celda vacía cuando el artículo no lleva goma (ver 014). El costo no viaja:
                    // es configuración global, no un dato del artículo.
                    $articulo->tamano_goma?->value,
                ]);
            }

            fclose($handle);
        }, 'articulos.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * Abre el CSV subido con su contenido ya en UTF-8 y sin BOM (ver 006-gestion-articulos.md).
     *
     * Una hoja de cálculo escribe el archivo en la codificación que corresponda a la opción de
     * guardado elegida, no en la que el sistema espera: "CSV (delimitado por comas)" de Excel en
     * español usa la ANSI del sistema (Windows-1252) y "CSV UTF-8" antepone un BOM. Un byte que no
     * es UTF-8 válido rompe la serialización JSON de la respuesta, así que la conversión va en la
     * puerta de entrada y no más adentro.
     *
     * Se detecta por contenido y una sola vez: un archivo es de una codificación o de la otra, no
     * de las dos. El parseo sigue en manos de `fgetcsv` sobre un stream, para no perder las celdas
     * entrecomilladas con saltos de línea.
     *
     * @return resource
     */
    private function abrirCsvComoUtf8(string $ruta)
    {
        $contenido = (string) file_get_contents($ruta);

        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'Windows-1252');
        }

        // El BOM sobrevive a `trim`, y con él la primera columna deja de llamarse `nombre`: el
        // archivo entero se rechazaría reclamando un campo que sí está presente.
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido) ?? $contenido;

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contenido);
        rewind($handle);

        return $handle;
    }

    /**
     * Lleva la celda de objeto de impuesto a su forma canónica de dos dígitos.
     *
     * Una hoja de cálculo trata `02` como número y lo guarda de vuelta como `2`, y esta es la única
     * columna del CSV con cero inicial: sin esta normalización, el archivo que el propio sistema
     * exporta deja de ser importable con solo abrirlo y guardarlo (ver 006-gestion-articulos.md).
     * Cualquier otra cosa se deja intacta para que la validación la rechace y la reporte.
     */
    private function normalizarObjetoImpuesto(string $celda): string
    {
        $valor = trim($celda);

        if (preg_match('/^\d$/', $valor) !== 1) {
            return $valor;
        }

        $canonico = str_pad($valor, 2, '0', STR_PAD_LEFT);

        // Se repone el cero solo si con él la clave existe: un dígito que no corresponde a ninguna
        // se deja como llegó, para que el motivo reporte lo que el usuario tiene en su hoja.
        return ObjetoImpuesto::tryFrom($canonico) !== null ? $canonico : $valor;
    }

    /**
     * Costo vigente de la goma del tamaño capturado, o 0 si el artículo no lleva goma.
     *
     * Se congela como copia en la ficha del artículo (ver 014-costo-elaboracion-goma.md): sin un
     * valor guardado no habría nada que recalcular al cambiar el ajuste, ni nada que confirmar
     * antes de mover precios, ni columna real por la que ordenar el listado.
     *
     * @param  array<string, mixed>  $datos
     */
    private function costoGomaDe(Request $request, array $datos): float
    {
        $tamano = $datos['tamano_goma'] ?? null;

        return app(ConfiguracionService::class)->costoGoma(
            $request->user(),
            $tamano instanceof TamanoGoma ? $tamano : TamanoGoma::tryFrom((string) $tamano),
        );
    }

    /**
     * Aplica ?sort= y ?direction= sobre las columnas numéricas del listado. Un `sort` no reconocido
     * se ignora y se cae al orden por defecto por nombre (ver 011-precio-proveedor-utilidad.md).
     *
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function ordenar(Builder $query, Request $request): Builder
    {
        $expresion = self::ORDENACIONES[$request->string('sort')->toString()] ?? null;

        if ($expresion === null) {
            return $query->orderBy('nombre');
        }

        $direccion = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw("$expresion $direccion")->orderBy('nombre');
    }

    /**
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function filtrarBusqueda(Builder $query, Request $request): Builder
    {
        return $query
            ->when($request->string('search')->trim()->isNotEmpty(), function ($query) use ($request) {
                $search = '%'.$request->string('search')->trim().'%';
                $query->where(function ($query) use ($search) {
                    $query->where('nombre', 'like', $search)
                        ->orWhere('modelo', 'like', $search)
                        ->orWhereHas('catalogo.proveedor', fn ($query) => $query->where('nombre_comercial', 'like', $search));
                });
            })
            // Alimenta el selector de artículos del formulario de Orden de compra: le compras a un
            // proveedor lo que ese proveedor vende (ver 012-ordenes-compra.md, adición técnica 41).
            ->when($request->integer('proveedor_id') > 0, function ($query) use ($request) {
                $query->whereHas('catalogo', fn ($q) => $q->where('proveedor_id', $request->integer('proveedor_id')));
            });
    }
}
