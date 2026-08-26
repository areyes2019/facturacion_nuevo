<?php

namespace App\Services;

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\Articulo;
use App\Models\Existencia;
use App\Models\MovimientoInventario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único punto del sistema que escribe la fila de `existencias` de un artículo (ver
 * 017-inventario.md). Un artículo sin fila no es inventario: esa ausencia es la marca de "nunca se
 * pasó a existencias", no un cero implícito.
 *
 * Todo el módulo se reduce a tres operaciones sobre el par (`existencia`, `faltante_pendiente`):
 *
 * - **Entrada** de N piezas (recepción de orden, devolución por cancelación, alta/ajuste manual):
 *   salda primero el faltante y solo el resto sube la existencia. **Siempre crea la fila si no
 *   existe** — comprar o dar de alta algo es, de por sí, decidir que se almacena.
 * - **Salida** de N piezas (factura timbrada, cotización entregada, pedido creado): baja la
 *   existencia hasta 0 y el excedente se acumula como faltante. Nunca bloquea ni produce un
 *   negativo. Solo crea la fila si no existe cuando se le pide explícitamente (Cotización); de lo
 *   contrario, sin fila, no hace nada.
 * - **Ajuste** manual: *fija* la cantidad final capturada y pone el faltante en 0. Siempre crea (o
 *   restaura) la fila.
 *
 * En las tres, la fila se bloquea antes de leer sus contadores y el movimiento se escribe en la
 * misma transacción: nunca puede quedar una existencia que el historial no explique, ni dos
 * operaciones simultáneas pisándose el resultado.
 */
class InventarioService
{
    /**
     * Entrada de N piezas. Salda primero el faltante pendiente; el resto sube la existencia.
     *
     * Debes 3 y entran 10 → existencia 7, faltante 0. Debes 3 y entran 2 → existencia 0, faltante 1.
     */
    public function entrada(
        Articulo $articulo,
        int $cantidad,
        MotivoMovimientoInventario $motivo,
        ?Model $documento = null,
        ?string $nota = null,
    ): ?MovimientoInventario {
        if ($cantidad <= 0) {
            return null;
        }

        return $this->aplicar(
            $articulo,
            TipoMovimientoInventario::Entrada,
            $cantidad,
            $motivo,
            $documento,
            $nota,
            crearSiNoExiste: true,
            calcular: function (int $existencia, int $faltante) use ($cantidad): array {
                $saldado = min($cantidad, $faltante);

                return [$existencia + ($cantidad - $saldado), $faltante - $saldado];
            },
        );
    }

    /**
     * Salida de N piezas. Baja la existencia hasta 0 y acumula el excedente como faltante.
     *
     * Nunca lanza ni bloquea: una salida sin fila de existencias, y sin `$crearSiNoExiste`, no hace
     * nada — igual que una línea sin `articulo_id`. Solo Cotización pasa `crearSiNoExiste: true`
     * (ver 017, "El vínculo factura → cotización"). Pedido de mostrador es la única excepción a
     * "nunca bloquea": esa validación vive antes, en `verificarDisponibilidadPedido()`.
     */
    public function salida(
        Articulo $articulo,
        int $cantidad,
        MotivoMovimientoInventario $motivo,
        ?Model $documento = null,
        ?string $nota = null,
        bool $crearSiNoExiste = false,
    ): ?MovimientoInventario {
        if ($cantidad <= 0) {
            return null;
        }

        return $this->aplicar(
            $articulo,
            TipoMovimientoInventario::Salida,
            $cantidad,
            $motivo,
            $documento,
            $nota,
            crearSiNoExiste: $crearSiNoExiste,
            calcular: function (int $existencia, int $faltante) use ($cantidad): array {
                $descontado = min($cantidad, $existencia);

                return [$existencia - $descontado, $faltante + ($cantidad - $descontado)];
            },
        );
    }

    /**
     * Ajuste manual: fija la cantidad final y borra el faltante.
     *
     * *Fija*, no suma: el usuario captura cuántas piezas hay, no cuántas cambiaron. Y borra el
     * faltante porque un faltante es un descuadre de registro y el usuario acaba de medir la
     * realidad con sus manos; arrastrarlo después de contar sería conservar un error ya corregido.
     *
     * Pasar un artículo a existencias por primera vez usa esta misma operación, con punto de
     * partida en cero: crea (o restaura) la fila si no existe.
     */
    public function ajuste(
        Articulo $articulo,
        int $cantidadFinal,
        MotivoMovimientoInventario $motivo,
        ?string $nota = null,
    ): MovimientoInventario {
        return $this->aplicar(
            $articulo,
            TipoMovimientoInventario::Ajuste,
            $cantidadFinal,
            $motivo,
            null,
            $nota,
            crearSiNoExiste: true,
            calcular: fn (): array => [$cantidadFinal, 0],
        );
    }

    /**
     * Quita un artículo de existencias (borrado lógico de su fila). No bloquea aunque tenga
     * existencia o faltante distintos de cero: el historial sigue ligado al artículo, no a esta
     * fila, así que nunca se pierde. Sin fila que borrar, no hace nada.
     */
    public function quitar(Articulo $articulo): void
    {
        Existencia::where('articulo_id', $articulo->id)->first()?->delete();
    }

    /**
     * Aplica una salida por cada artículo de un documento de venta (factura, cotización o pedido).
     *
     * @param  iterable<int, object>  $lineas
     * @return array<int, MovimientoInventario>
     */
    public function salidaPorDocumento(
        iterable $lineas,
        MotivoMovimientoInventario $motivo,
        Model $documento,
        bool $crearSiNoExiste = false,
    ): array {
        return $this->porDocumento($lineas, $motivo, $documento, 'salida', $crearSiNoExiste);
    }

    /**
     * Aplica una entrada por cada artículo de un documento (recepción de orden, cancelación,
     * corrección de pedido). Siempre crea la fila si no existe.
     *
     * @param  iterable<int, object>  $lineas
     * @return array<int, MovimientoInventario>
     */
    public function entradaPorDocumento(
        iterable $lineas,
        MotivoMovimientoInventario $motivo,
        Model $documento,
    ): array {
        return $this->porDocumento($lineas, $motivo, $documento, 'entrada');
    }

    /**
     * Revisa, sin mover nada, que cada artículo de un Pedido tenga existencia antes de guardarlo.
     * Es la única excepción del sistema a "una salida nunca bloquea la venta" (ver 017,
     * "Bloqueo de venta sin existencia en Pedido").
     *
     * Vender por arriba de lo disponible (existencia 3, piden 5) no se rechaza aquí: solo se
     * rechaza un artículo sin fila en `existencias`, o con existencia exactamente en 0.
     *
     * @param  iterable<int, array<string, mixed>|object>  $lineas
     *
     * @throws ValidationException
     */
    public function verificarDisponibilidadPedido(iterable $lineas): void
    {
        $cantidades = $this->cantidadesPorArticulo($lineas);

        if ($cantidades === []) {
            return;
        }

        $existencias = Existencia::whereIn('articulo_id', array_keys($cantidades))
            ->pluck('existencia', 'articulo_id');

        $sinExistencia = array_filter(
            array_keys($cantidades),
            fn (int $articuloId) => (int) ($existencias[$articuloId] ?? 0) <= 0,
        );

        if ($sinExistencia === []) {
            return;
        }

        $nombres = Articulo::withTrashed()->whereIn('id', $sinExistencia)->pluck('nombre', 'id');

        throw ValidationException::withMessages([
            'lineas' => array_values(array_map(
                fn (int $articuloId) => 'No hay existencia de "'.($nombres[$articuloId] ?? "artículo #{$articuloId}").'".',
                $sinExistencia,
            )),
        ]);
    }

    /**
     * Agrupa las líneas por artículo, suma sus cantidades y aplica un movimiento por artículo.
     *
     * La agrupación es una **red defensiva**, no una funcionalidad visible: la regla de "un
     * artículo por línea" de 008 vive solo en el componente `DocumentoLineas` del frontend, y sus
     * Form Requests siguen aceptando `articulo_id` repetido. Sin agrupar, dos líneas del mismo
     * artículo se pisarían y perderían piezas en silencio.
     *
     * Las líneas sin `articulo_id` (texto libre, permitido desde 012) se descartan: no hay
     * existencia a la que sumarlas.
     *
     * @param  iterable<int, object>  $lineas
     * @return array<int, MovimientoInventario>
     */
    private function porDocumento(
        iterable $lineas,
        MotivoMovimientoInventario $motivo,
        Model $documento,
        string $direccion,
        bool $crearSiNoExiste = false,
    ): array {
        $cantidades = $this->cantidadesPorArticulo($lineas);
        $movimientos = [];

        foreach ($cantidades as $articuloId => $cantidad) {
            $articulo = Articulo::withTrashed()->find($articuloId);

            if ($articulo === null) {
                continue;
            }

            $movimiento = $direccion === 'entrada'
                ? $this->entrada($articulo, $cantidad, $motivo, $documento)
                : $this->salida($articulo, $cantidad, $motivo, $documento, crearSiNoExiste: $crearSiNoExiste);

            if ($movimiento !== null) {
                $movimientos[] = $movimiento;
            }
        }

        return $movimientos;
    }

    /**
     * @param  iterable<int, array<string, mixed>|object>  $lineas
     * @return array<int, int> articulo_id => cantidad total
     */
    public function cantidadesPorArticulo(iterable $lineas): array
    {
        $cantidades = [];

        foreach ($lineas as $linea) {
            $articuloId = is_array($linea) ? ($linea['articulo_id'] ?? null) : $linea->articulo_id;

            if ($articuloId === null) {
                continue;
            }

            $cantidad = is_array($linea) ? $linea['cantidad'] : $linea->cantidad;

            $articuloId = (int) $articuloId;
            $cantidades[$articuloId] = ($cantidades[$articuloId] ?? 0) + (int) $cantidad;
        }

        return $cantidades;
    }

    /**
     * Bloquea la fila de existencias (creándola o restaurándola primero si hace falta), calcula el
     * nuevo par de contadores, y guarda fila y movimiento en la misma transacción.
     *
     * El bloqueo es lo que impide que dos salidas simultáneas del mismo artículo lean ambas la
     * misma existencia y la segunda pise a la primera (nueve piezas fuera, cuatro registradas).
     *
     * @param  callable(int, int): array{0: int, 1: int}  $calcular
     */
    private function aplicar(
        Articulo $articulo,
        TipoMovimientoInventario $tipo,
        int $cantidad,
        MotivoMovimientoInventario $motivo,
        ?Model $documento,
        ?string $nota,
        bool $crearSiNoExiste,
        callable $calcular,
    ): ?MovimientoInventario {
        return DB::transaction(function () use ($articulo, $tipo, $cantidad, $motivo, $documento, $nota, $crearSiNoExiste, $calcular): ?MovimientoInventario {
            $existencia = Existencia::withTrashed()
                ->where('articulo_id', $articulo->id)
                ->lockForUpdate()
                ->first();

            if ($existencia === null) {
                if (! $crearSiNoExiste) {
                    return null;
                }

                $existencia = Existencia::create(['articulo_id' => $articulo->id]);
                $existencia = Existencia::where('id', $existencia->id)->lockForUpdate()->first();
            } elseif ($existencia->trashed()) {
                if (! $crearSiNoExiste) {
                    return null;
                }

                $existencia->restore();
            }

            [$exist, $falt] = $calcular((int) $existencia->existencia, (int) $existencia->faltante_pendiente);

            $existencia->forceFill([
                'existencia' => $exist,
                'faltante_pendiente' => $falt,
            ])->save();

            $movimiento = new MovimientoInventario([
                'articulo_id' => $articulo->id,
                'tipo' => $tipo->value,
                'motivo' => $motivo->value,
                'cantidad' => $cantidad,
                'existencia_resultante' => $exist,
                'faltante_resultante' => $falt,
                'nota' => $nota,
            ]);

            $movimiento->user_id = $articulo->user_id;

            if ($documento !== null) {
                $movimiento->documentable()->associate($documento);
            }

            $movimiento->save();

            return $movimiento;
        });
    }

    /**
     * Reaplica el historial completo de cada artículo desde cero y reporta dónde no coincide con
     * la fila de existencias guardada.
     *
     * **Solo reporta; no corrige nada.** Un descuadre se corrige con un ajuste manual, que queda
     * registrado como tal: una reparación silenciosa borraría la evidencia de que algo estuvo mal.
     *
     * @return Collection<int, array{articulo: Articulo, existencia_guardada: int, existencia_calculada: int, faltante_guardado: int, faltante_calculado: int}>
     */
    public function auditar(User $user): Collection
    {
        $existencias = Existencia::withTrashed()
            ->whereHas('articulo', fn ($q) => $q->where('user_id', $user->id))
            ->with('articulo')
            ->get();

        $movimientos = MovimientoInventario::where('user_id', $user->id)
            ->orderBy('articulo_id')
            ->orderBy('id')
            ->get()
            ->groupBy('articulo_id');

        return $existencias
            ->map(function (Existencia $existencia) use ($movimientos): ?array {
                [$exist, $falt] = $this->reconstruir($movimientos->get($existencia->articulo_id) ?? collect());

                if ($exist === (int) $existencia->existencia && $falt === (int) $existencia->faltante_pendiente) {
                    return null;
                }

                return [
                    'articulo' => $existencia->articulo,
                    'existencia_guardada' => (int) $existencia->existencia,
                    'existencia_calculada' => $exist,
                    'faltante_guardado' => (int) $existencia->faltante_pendiente,
                    'faltante_calculado' => $falt,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @param  Collection<int, MovimientoInventario>  $movimientos
     * @return array{0: int, 1: int}
     */
    private function reconstruir(Collection $movimientos): array
    {
        $existencia = 0;
        $faltante = 0;

        foreach ($movimientos as $movimiento) {
            $cantidad = (int) $movimiento->cantidad;

            switch ($movimiento->tipo) {
                case TipoMovimientoInventario::Entrada:
                    $saldado = min($cantidad, $faltante);
                    $faltante -= $saldado;
                    $existencia += $cantidad - $saldado;
                    break;
                case TipoMovimientoInventario::Salida:
                    $descontado = min($cantidad, $existencia);
                    $existencia -= $descontado;
                    $faltante += $cantidad - $descontado;
                    break;
                case TipoMovimientoInventario::Ajuste:
                    $existencia = $cantidad;
                    $faltante = 0;
                    break;
            }
        }

        return [$existencia, $faltante];
    }
}
