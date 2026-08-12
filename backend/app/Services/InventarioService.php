<?php

namespace App\Services;

use App\Enums\MotivoMovimientoInventario;
use App\Enums\TipoMovimientoInventario;
use App\Models\Articulo;
use App\Models\MovimientoInventario;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Único punto del sistema que escribe `existencia` y `faltante_pendiente` (ver 017-inventario.md).
 *
 * Todo el módulo se reduce a tres operaciones sobre ese par de números:
 *
 * - **Entrada** de N piezas (recepción de orden, devolución por cancelación): salda primero el
 *   faltante y solo el resto sube la existencia.
 * - **Salida** de N piezas (factura timbrada, cotización entregada): baja la existencia hasta 0 y
 *   el excedente se acumula como faltante. Nunca bloquea la venta ni produce un negativo.
 * - **Ajuste** manual: *fija* la cantidad final capturada y pone el faltante en 0.
 *
 * En las tres, el artículo se bloquea antes de leer sus contadores y el movimiento se escribe en la
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
            function (int $existencia, int $faltante) use ($cantidad): array {
                $saldado = min($cantidad, $faltante);

                return [$existencia + ($cantidad - $saldado), $faltante - $saldado];
            },
        );
    }

    /**
     * Salida de N piezas. Baja la existencia hasta 0 y acumula el excedente como faltante.
     *
     * Nunca lanza ni bloquea: el inventario arranca en cero y detener los timbrados hasta terminar
     * de cargar existencias haría el sistema inusable (ver 017, supuesto 17).
     */
    public function salida(
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
            TipoMovimientoInventario::Salida,
            $cantidad,
            $motivo,
            $documento,
            $nota,
            function (int $existencia, int $faltante) use ($cantidad): array {
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
     * Meter un artículo al inventario por primera vez usa esta misma operación, con punto de
     * partida en cero.
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
            fn (): array => [$cantidadFinal, 0],
        );
    }

    /**
     * Aplica una salida por cada artículo de un documento de venta (factura o cotización).
     *
     * @param  iterable<int, object>  $lineas
     * @return array<int, MovimientoInventario>
     */
    public function salidaPorDocumento(
        iterable $lineas,
        MotivoMovimientoInventario $motivo,
        Model $documento,
    ): array {
        return $this->porDocumento($lineas, $motivo, $documento, 'salida');
    }

    /**
     * Aplica una entrada por cada artículo de un documento (recepción de orden, cancelación).
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
                : $this->salida($articulo, $cantidad, $motivo, $documento);

            if ($movimiento !== null) {
                $movimientos[] = $movimiento;
            }
        }

        return $movimientos;
    }

    /**
     * @param  iterable<int, object>  $lineas
     * @return array<int, int> articulo_id => cantidad total
     */
    public function cantidadesPorArticulo(iterable $lineas): array
    {
        $cantidades = [];

        foreach ($lineas as $linea) {
            if ($linea->articulo_id === null) {
                continue;
            }

            $articuloId = (int) $linea->articulo_id;
            $cantidades[$articuloId] = ($cantidades[$articuloId] ?? 0) + (int) $linea->cantidad;
        }

        return $cantidades;
    }

    /**
     * Bloquea el artículo, calcula el nuevo par de contadores, y guarda columnas y movimiento en la
     * misma transacción.
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
        callable $calcular,
    ): MovimientoInventario {
        return DB::transaction(function () use ($articulo, $tipo, $cantidad, $motivo, $documento, $nota, $calcular): MovimientoInventario {
            $bloqueado = Articulo::withTrashed()->lockForUpdate()->findOrFail($articulo->id);

            [$existencia, $faltante] = $calcular(
                (int) $bloqueado->existencia,
                (int) $bloqueado->faltante_pendiente,
            );

            $bloqueado->forceFill([
                'existencia' => $existencia,
                'faltante_pendiente' => $faltante,
            ])->save();

            $movimiento = new MovimientoInventario([
                'articulo_id' => $bloqueado->id,
                'tipo' => $tipo->value,
                'motivo' => $motivo->value,
                'cantidad' => $cantidad,
                'existencia_resultante' => $existencia,
                'faltante_resultante' => $faltante,
                'nota' => $nota,
            ]);

            $movimiento->user_id = $bloqueado->user_id;

            if ($documento !== null) {
                $movimiento->documentable()->associate($documento);
            }

            $movimiento->save();

            // El modelo que recibió el llamador debe reflejar el resultado, no el estado previo.
            $articulo->forceFill([
                'existencia' => $existencia,
                'faltante_pendiente' => $faltante,
            ])->syncOriginalAttributes(['existencia', 'faltante_pendiente']);

            return $movimiento;
        });
    }

    /**
     * Reaplica el historial completo de cada artículo desde cero y reporta dónde no coincide con
     * las columnas guardadas.
     *
     * **Solo reporta; no corrige nada.** Un descuadre se corrige con un ajuste manual, que queda
     * registrado como tal: una reparación silenciosa borraría la evidencia de que algo estuvo mal.
     *
     * @return Collection<int, array{articulo: Articulo, existencia_guardada: int, existencia_calculada: int, faltante_guardado: int, faltante_calculado: int}>
     */
    public function auditar(User $user): Collection
    {
        $movimientos = MovimientoInventario::where('user_id', $user->id)
            ->orderBy('articulo_id')
            ->orderBy('id')
            ->get()
            ->groupBy('articulo_id');

        return $user->articulos()->withTrashed()->get()
            ->map(function (Articulo $articulo) use ($movimientos): ?array {
                [$existencia, $faltante] = $this->reconstruir($movimientos->get($articulo->id) ?? collect());

                if ($existencia === (int) $articulo->existencia && $faltante === (int) $articulo->faltante_pendiente) {
                    return null;
                }

                return [
                    'articulo' => $articulo,
                    'existencia_guardada' => (int) $articulo->existencia,
                    'existencia_calculada' => $existencia,
                    'faltante_guardado' => (int) $articulo->faltante_pendiente,
                    'faltante_calculado' => $faltante,
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
