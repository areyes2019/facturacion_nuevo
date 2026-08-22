<?php

namespace App\Http\Controllers;

use App\Enums\ObjetoImpuesto;
use App\Enums\TamanoGoma;
use App\Http\Requests\Articulos\EliminarLoteArticulosRequest;
use App\Http\Requests\Articulos\ListaPreciosArticulosRequest;
use App\Http\Requests\Articulos\MoverLoteArticulosRequest;
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
     * `tamano_goma` y `utilidad_distribuidor_porcentaje` van al final, en el orden en que se
     * agregaron, para que un CSV de una historia anterior siga siendo importable sin cambios (ver
     * 014-costo-elaboracion-goma.md y 033-precio-distribuidor.md).
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
        'utilidad_distribuidor_porcentaje',
    ];

    /**
     * Columnas numéricas ordenables del listado (ver 011-precio-proveedor-utilidad.md). Ni el costo
     * total ni la utilidad están persistidos, así que se ordena por la expresión que los define
     * (ver 014-costo-elaboracion-goma.md). `precio_distribuidor_sin_iva` sí está persistida (ver
     * 033-precio-distribuidor.md), así que se ordena por la columna directamente.
     *
     * Son las únicas cuatro: el orden de captura no se pide, es el que sale cuando no se pide nada
     * (ver 025-filtros-columna-listado-articulos.md).
     */
    private const ORDENACIONES = [
        'costo_total' => 'costo_con_descuento + costo_goma',
        'precio_unitario_sin_iva' => 'precio_unitario_sin_iva',
        'utilidad' => 'precio_unitario_sin_iva - (costo_con_descuento + costo_goma)',
        'precio_distribuidor_sin_iva' => 'precio_distribuidor_sin_iva',
    ];

    /**
     * Filtros de rango del listado: prefijo del parámetro => clave de `ORDENACIONES`
     * (ver 025-filtros-columna-listado-articulos.md).
     *
     * Apuntan a `ORDENACIONES` en vez de repetir la fórmula para que **filtrar y ordenar no puedan
     * entender cosas distintas**: una tabla donde el rango 100–150 dejara fuera un artículo que ella
     * misma muestra con costo de $120 sería un error invisible.
     */
    private const RANGOS = [
        'costo' => 'costo_total',
        'precio' => 'precio_unitario_sin_iva',
        'utilidad' => 'utilidad',
        'distribuidor' => 'precio_distribuidor_sin_iva',
    ];

    /**
     * Filtros de texto del listado: parámetro => columna. Van con prefijo `filtro_` para no chocar
     * con el `search` global, con el que se combinan con Y
     * (ver 025-filtros-columna-listado-articulos.md).
     */
    private const FILTROS_TEXTO = [
        'filtro_nombre' => 'nombre',
        'filtro_modelo' => 'modelo',
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
            ObjetoImpuesto::from($datos['objeto_imp']),
            (float) ($datos['utilidad_distribuidor_porcentaje'] ?? $catalogo->utilidad_distribuidor_porcentaje),
        );

        $articulo = $request->user()->articulos()->create([
            ...$datos,
            'costo_goma' => $costoGoma,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
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
            ObjetoImpuesto::from($datos['objeto_imp']),
            (float) ($datos['utilidad_distribuidor_porcentaje'] ?? $catalogo->utilidad_distribuidor_porcentaje),
        );

        $articulo->update([
            ...$datos,
            'costo_goma' => $costoGoma,
            'costo_con_descuento' => $cadena['costo_con_descuento'],
            'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
            'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
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
     * Mueve artículos en lote a otro catálogo (ver
     * 034-filtro-catalogo-y-mover-lote-articulos.md), recalculando su cadena de precios con el
     * descuento y las utilidades del catálogo destino.
     *
     * Mismo molde que `eliminarLote`: una sola petición con la lista de identificadores, todo
     * dentro de una transacción, todo o nada. La pertenencia de cada `id` y del `catalogo_id` al
     * usuario ya la garantizó `MoverLoteArticulosRequest`.
     *
     * El recálculo usa `PrecioArticuloCalculator::calcularCadena()` artículo por artículo, la
     * misma pieza que ya usan el alta, la edición, la importación CSV, el aumento de costos (021)
     * y `Catalogo::booted()` (011, 033): un `UPDATE` masivo en SQL no sería portable por el techo
     * a 2 decimales del markup.
     */
    public function moverLote(MoverLoteArticulosRequest $request): JsonResponse
    {
        $ids = $request->validated()['ids'];
        $catalogo = Catalogo::findOrFail($request->validated()['catalogo_id']);

        $movidos = DB::transaction(function () use ($request, $ids, $catalogo): int {
            $articulos = $request->user()->articulos()->whereIn('id', $ids)->get();

            foreach ($articulos as $articulo) {
                $utilidadEfectiva = (float) ($articulo->utilidad_porcentaje ?? $catalogo->utilidad_porcentaje);
                $utilidadDistribuidorEfectiva = (float) ($articulo->utilidad_distribuidor_porcentaje ?? $catalogo->utilidad_distribuidor_porcentaje);

                $cadena = PrecioArticuloCalculator::calcularCadena(
                    (float) $articulo->precio_proveedor,
                    (float) $catalogo->descuento,
                    $utilidadEfectiva,
                    (float) $articulo->costo_goma,
                    $articulo->objeto_imp,
                    $utilidadDistribuidorEfectiva,
                );

                $articulo->update([
                    'catalogo_id' => $catalogo->id,
                    'costo_con_descuento' => $cadena['costo_con_descuento'],
                    'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
                    'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
                ]);
            }

            return $articulos->count();
        });

        return response()->json(['movidos' => $movidos]);
    }

    /**
     * Genera en el servidor el PDF de una lista de precios con los artículos seleccionados en el
     * listado (ver 028-lista-precios-pdf.md), con su precio distribuidor con IVA incluido.
     *
     * Es un documento efímero: no se guarda ningún registro ni se asocia a ningún cliente, se
     * genera al vuelo y se entrega en la propia respuesta, igual de espíritu que el ticket de
     * mostrador (`TicketPedidoService`).
     */
    public function listaPrecios(ListaPreciosArticulosRequest $request): Response
    {
        $ids = $request->validated()['ids'];

        $articulos = $request->user()->articulos()
            ->whereIn('id', $ids)
            ->with('catalogo')
            ->get()
            ->sortBy('nombre', SORT_NATURAL | SORT_FLAG_CASE);

        // Agrupar y ordenar en el mismo paso: `groupBy` conserva el orden relativo de la colección
        // de origen, así que ya no hace falta ordenar dentro de cada grupo por separado.
        $secciones = $articulos
            ->groupBy(fn (Articulo $articulo): string => $articulo->catalogo?->nombre ?? 'Sin catálogo')
            ->sortKeys();

        $pdf = app('dompdf.wrapper')->loadView('pdf.lista-precios', [
            // Solo se muestran subtítulos de sección cuando la selección mezcla más de un
            // catálogo: con uno solo, un único encabezado de sección sería ruido.
            'secciones' => $secciones,
            'mostrarSecciones' => $secciones->count() > 1,
            'fecha' => now(),
        ]);

        return $pdf->stream('lista-precios-'.now()->format('Y-m-d').'.pdf');
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

            // Misma idea para la utilidad distribuidor (ver 033-precio-distribuidor.md).
            $utilidadDistribuidor = trim((string) ($datos['utilidad_distribuidor_porcentaje'] ?? ''));

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
                    'utilidad_distribuidor_porcentaje' => $utilidadDistribuidor === '' ? null : $utilidadDistribuidor,
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
                    'utilidad_distribuidor_porcentaje' => ['nullable', 'numeric', 'gte:0', 'lte:999.99', 'decimal:0,2'],
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
                ObjetoImpuesto::from($filaValidada['objeto_imp']),
                (float) ($filaValidada['utilidad_distribuidor_porcentaje'] ?? $catalogo->utilidad_distribuidor_porcentaje),
            );

            $request->user()->articulos()->create([
                ...$filaValidada,
                'catalogo_id' => $catalogo->id,
                'costo_goma' => $costoGoma,
                'costo_con_descuento' => $cadena['costo_con_descuento'],
                'precio_unitario_sin_iva' => $cadena['precio_unitario_sin_iva'],
                'precio_distribuidor_sin_iva' => $cadena['precio_distribuidor_sin_iva'],
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
                    // Celda vacía cuando el artículo hereda la utilidad distribuidor del catálogo
                    // (ver 033-precio-distribuidor.md), mismo criterio que utilidad_porcentaje.
                    $articulo->utilidad_distribuidor_porcentaje,
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
     * Aplica ?sort= y ?direction= sobre las columnas numéricas del listado
     * (ver 011-precio-proveedor-utilidad.md).
     *
     * Sin `sort` —o con uno que no se reconoce— el listado sale en **orden de captura**: `id`
     * ascendente, que para una importación masiva es el orden de las filas del archivo. Es el orden
     * base y no se pide, porque un orden que hubiera que pedir cada vez no resuelve "quiero ver lo
     * que subí" (ver 025-filtros-columna-listado-articulos.md).
     *
     * El desempate también es por `id` y no por `nombre`: dos artículos del mismo precio salen en el
     * orden en que se cargaron, igual que salen cuando no hay ninguna ordenación pedida.
     *
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function ordenar(Builder $query, Request $request): Builder
    {
        $expresion = self::ORDENACIONES[$request->string('sort')->toString()] ?? null;

        if ($expresion === null) {
            return $query->orderBy('id');
        }

        $direccion = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        return $query->orderByRaw("$expresion $direccion")->orderBy('id');
    }

    /**
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function filtrarBusqueda(Builder $query, Request $request): Builder
    {
        $query
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

        return $this->filtrarPorColumna($query, $request);
    }

    /**
     * Filtros de la fila de cabeceras del listado (ver 025-filtros-columna-listado-articulos.md).
     *
     * Todos se combinan con Y entre sí, con el `search` global y con `proveedor_id`. Son aditivos:
     * una petición que no los manda responde exactamente lo que respondía antes de la historia 025,
     * así que nada de lo que ya consume el endpoint se ve afectado.
     *
     * @param  Builder<Articulo>  $query
     * @return Builder<Articulo>
     */
    private function filtrarPorColumna(Builder $query, Request $request): Builder
    {
        foreach (self::FILTROS_TEXTO as $parametro => $columna) {
            $valor = $request->string($parametro)->trim()->toString();

            $query->when($valor !== '', fn ($query) => $query->where($columna, 'like', '%'.$valor.'%'));
        }

        $query->when(
            $request->integer('filtro_catalogo_id') > 0,
            fn ($query) => $query->where('catalogo_id', $request->integer('filtro_catalogo_id')),
        );

        foreach (self::RANGOS as $prefijo => $ordenacion) {
            $expresion = self::ORDENACIONES[$ordenacion];

            // Cada extremo es independiente: solo el mínimo es "de ahí para arriba", solo el máximo
            // es "de ahí para abajo", y los dos juntos delimitan. Escribir un solo número es como se
            // usa la mitad de las veces.
            foreach (['min' => '>=', 'max' => '<='] as $extremo => $operador) {
                $valor = $this->numeroDeFiltro($request, $prefijo.'_'.$extremo);

                if ($valor !== null) {
                    // El `CAST` no es adorno: el extremo viaja al motor como texto —PDO no tiene
                    // parámetro de punto flotante— y una suma como `costo_con_descuento + costo_goma`
                    // no arrastra la afinidad numérica de sus columnas. Comparada contra texto,
                    // SQLite da el número por menor **siempre**, así que sin el CAST todo mínimo no
                    // filtraría nada y todo máximo dejaría pasar el catálogo entero.
                    $query->whereRaw("($expresion) $operador CAST(? AS DECIMAL(10,2))", [$valor]);
                }
            }
        }

        return $query;
    }

    /**
     * Extremo de un filtro de rango, o `null` si no hay nada que filtrar.
     *
     * Un valor vacío, no numérico o negativo **se ignora en silencio**, igual que un `sort` que no se
     * reconoce: mientras se teclea "1", "12", "125" los tres estados son legítimos, y un filtro a
     * medio escribir no puede vaciar la tabla ni sacar un error en pantalla
     * (ver 025-filtros-columna-listado-articulos.md).
     */
    private function numeroDeFiltro(Request $request, string $parametro): ?float
    {
        $valor = $request->string($parametro)->trim()->toString();

        if ($valor === '' || ! is_numeric($valor) || (float) $valor < 0) {
            return null;
        }

        return (float) $valor;
    }
}
