<?php

namespace App\Services;

use App\Enums\TipoMovimiento;
use App\Models\Cuenta;
use App\Models\Movimiento;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Punto único de escritura de movimientos y saldos (ver 010-tesoreria.md).
 *
 * Toda operación corre dentro de una transacción que bloquea con `lockForUpdate()` la(s) fila(s)
 * de `Cuenta` involucrada(s), persiste el/los movimiento(s), recalcula `saldo_actual` y valida que
 * no quede negativo. El bloqueo explícito evita que dos movimientos simultáneos sobre la misma
 * cuenta compitan y dejen el saldo mal calculado; a diferencia del folio de `Factura` en 007
 * (riesgo menor conocido y aceptado), aquí sí se mitiga porque afecta el saldo real de dinero.
 *
 * La validación de saldo no negativo se hace después de escribir y recalcular, no antes: si falla,
 * la excepción revierte la transacción completa. Así el saldo contra el que se compara es siempre
 * el definitivo, y la misma comprobación cubre altas, ediciones y transferencias sin duplicar la
 * aritmética de cada tipo de movimiento.
 */
class TesoreriaService
{
    /**
     * Tolerancia para comparar el saldo contra cero: `saldo_actual` es decimal(12,2) y se compara
     * como float, así que un residuo por debajo de medio centavo no es un saldo negativo real.
     */
    private const EPSILON = 0.005;

    /**
     * Registra un movimiento manual de una sola cuenta (ingreso, egreso o ajuste).
     *
     * @param  array{cuenta_id: int|string, tipo: string, monto: float|string, fecha: string, concepto: string}  $datos
     */
    public function registrarManual(User $user, array $datos): Movimiento
    {
        return DB::transaction(function () use ($user, $datos) {
            $cuenta = $this->bloquear($user, (int) $datos['cuenta_id']);

            $movimiento = $this->crear($user, $cuenta, [
                'tipo' => $datos['tipo'],
                'monto' => $this->montoSegunTipo(TipoMovimiento::from($datos['tipo']), (float) $datos['monto']),
                'fecha' => $datos['fecha'],
                'concepto' => $datos['concepto'],
            ]);

            $this->recalcularYValidar($cuenta);

            return $movimiento;
        });
    }

    /**
     * Traslada dinero entre dos cuentas propias: dos filas de `Movimiento` vinculadas por un mismo
     * `transferencia_id` (una negativa en la cuenta origen, otra positiva en la destino), dentro de
     * una sola transacción y con ambas cuentas bloqueadas.
     *
     * @param  array{cuenta_origen_id: int|string, cuenta_destino_id: int|string, monto: float|string, fecha: string, concepto: string}  $datos
     * @return array{0: Movimiento, 1: Movimiento}
     */
    public function registrarTransferencia(User $user, array $datos): array
    {
        return DB::transaction(function () use ($user, $datos) {
            // Se bloquean en orden de id para que dos transferencias cruzadas entre las mismas dos
            // cuentas no puedan quedarse esperando la una a la otra (deadlock).
            [$origen, $destino] = $this->bloquearPar(
                $user,
                (int) $datos['cuenta_origen_id'],
                (int) $datos['cuenta_destino_id'],
            );

            $monto = round((float) $datos['monto'], 2);
            $transferenciaId = (string) Str::uuid();

            $salida = $this->crear($user, $origen, [
                'tipo' => TipoMovimiento::Transferencia->value,
                'monto' => -$monto,
                'fecha' => $datos['fecha'],
                'concepto' => $datos['concepto'],
                'transferencia_id' => $transferenciaId,
            ]);

            $entrada = $this->crear($user, $destino, [
                'tipo' => TipoMovimiento::Transferencia->value,
                'monto' => $monto,
                'fecha' => $datos['fecha'],
                'concepto' => $datos['concepto'],
                'transferencia_id' => $transferenciaId,
            ]);

            $this->recalcularYValidar($origen);
            $this->recalcularYValidar($destino);

            return [$salida, $entrada];
        });
    }

    /**
     * Genera el movimiento automático que corresponde a un documento de otro módulo: hoy un
     * `CotizacionPago` (ingreso, 010) o una `OrdenCompra` pagada (egreso, 012). El `tipo` es
     * parámetro justamente para eso; el `concepto` lo arma quien llama a partir del documento
     * origen y no es editable por el usuario.
     *
     * La regla de saldo no negativo, que con ingresos nunca se activaba, sí aplica a los egresos:
     * si el pago dejaría la cuenta por debajo de cero, la excepción revierte la transacción
     * completa y el documento no queda marcado como pagado.
     */
    public function registrarDesdeDocumento(
        User $user,
        Model $documento,
        int $cuentaId,
        TipoMovimiento $tipo,
        float $monto,
        string $fecha,
        string $concepto,
    ): Movimiento {
        return DB::transaction(function () use ($user, $documento, $cuentaId, $tipo, $monto, $fecha, $concepto) {
            $cuenta = $this->bloquear($user, $cuentaId);

            $movimiento = $this->crear($user, $cuenta, [
                'tipo' => $tipo->value,
                'monto' => $this->montoSegunTipo($tipo, $monto),
                'fecha' => $fecha,
                'concepto' => $concepto,
            ]);

            $movimiento->documentable()->associate($documento);
            $movimiento->save();

            $this->recalcularYValidar($cuenta);

            return $movimiento;
        });
    }

    /**
     * Edita un movimiento manual de una sola cuenta. Puede cambiar de cuenta: en ese caso se
     * recalculan las dos (la que pierde el movimiento y la que lo recibe).
     *
     * @param  array{cuenta_id: int|string, tipo: string, monto: float|string, fecha: string, concepto: string}  $datos
     */
    public function actualizarManual(Movimiento $movimiento, array $datos): Movimiento
    {
        return DB::transaction(function () use ($movimiento, $datos) {
            $user = $movimiento->user;

            [$anterior, $nueva] = $this->bloquearPar($user, $movimiento->cuenta_id, (int) $datos['cuenta_id']);

            $movimiento->update([
                'cuenta_id' => (int) $datos['cuenta_id'],
                'tipo' => $datos['tipo'],
                'monto' => $this->montoSegunTipo(TipoMovimiento::from($datos['tipo']), (float) $datos['monto']),
                'fecha' => $datos['fecha'],
                'concepto' => $datos['concepto'],
            ]);

            $this->recalcularYValidar($anterior);

            if ($nueva->id !== $anterior->id) {
                $this->recalcularYValidar($nueva);
            }

            return $movimiento->fresh(['cuenta']);
        });
    }

    /**
     * Elimina un movimiento y recalcula el saldo. Si forma parte de una transferencia, elimina
     * también su fila hermana: las dos siempre se eliminan juntas, como una unidad.
     */
    public function eliminar(Movimiento $movimiento): void
    {
        DB::transaction(function () use ($movimiento) {
            $movimientos = $movimiento->transferencia_id !== null
                ? Movimiento::where('transferencia_id', $movimiento->transferencia_id)->get()
                : collect([$movimiento]);

            $cuentas = $this->bloquearVarias(
                $movimiento->user,
                $movimientos->pluck('cuenta_id')->unique()->all(),
            );

            Movimiento::whereIn('id', $movimientos->pluck('id'))->delete();

            foreach ($cuentas as $cuenta) {
                $this->recalcularYValidar($cuenta);
            }
        });
    }

    /**
     * Recalcula y persiste `saldo_actual` como `saldo_inicial + Σ(movimientos de la cuenta)`.
     *
     * La suma se resuelve en SQL con un CASE portable entre MySQL y SQLite: `ingreso` suma su
     * monto, `egreso` lo resta, y `ajuste`/`transferencia` ya lo guardan firmado.
     */
    public function recalcularSaldo(Cuenta $cuenta): void
    {
        $suma = (float) $cuenta->movimientos()
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN tipo = ? THEN monto WHEN tipo = ? THEN -monto ELSE monto END), 0) as total',
                [TipoMovimiento::Ingreso->value, TipoMovimiento::Egreso->value],
            )
            ->value('total');

        $cuenta->update(['saldo_actual' => round((float) $cuenta->saldo_inicial + $suma, 2)]);
    }

    /**
     * @param  array<string, mixed>  $atributos
     */
    private function crear(User $user, Cuenta $cuenta, array $atributos): Movimiento
    {
        $movimiento = new Movimiento($atributos + ['cuenta_id' => $cuenta->id]);
        $movimiento->user()->associate($user);
        $movimiento->save();

        return $movimiento;
    }

    /**
     * `ingreso` y `egreso` siempre guardan el monto en positivo — el signo lo determina el tipo, no
     * el valor capturado; `ajuste` conserva el signo tal cual se capturó.
     */
    private function montoSegunTipo(TipoMovimiento $tipo, float $monto): float
    {
        return round($tipo === TipoMovimiento::Ajuste ? $monto : abs($monto), 2);
    }

    private function bloquear(User $user, int $cuentaId): Cuenta
    {
        return Cuenta::where('user_id', $user->id)->lockForUpdate()->findOrFail($cuentaId);
    }

    /**
     * @return array{0: Cuenta, 1: Cuenta}
     */
    private function bloquearPar(User $user, int $primeraId, int $segundaId): array
    {
        $cuentas = $this->bloquearVarias($user, [$primeraId, $segundaId]);

        return [$cuentas[$primeraId], $cuentas[$segundaId]];
    }

    /**
     * Bloquea las cuentas indicadas en orden ascendente de id, para que dos operaciones que tocan
     * el mismo par de cuentas las tomen siempre en la misma secuencia y no se bloqueen entre sí.
     *
     * @param  array<int, int>  $cuentaIds
     * @return array<int, Cuenta> indexado por id de cuenta
     */
    private function bloquearVarias(User $user, array $cuentaIds): array
    {
        $ids = array_values(array_unique($cuentaIds));
        sort($ids);

        $cuentas = [];
        foreach ($ids as $id) {
            $cuentas[$id] = $this->bloquear($user, $id);
        }

        return $cuentas;
    }

    /**
     * El saldo de una cuenta nunca puede quedar negativo: aplica a egresos, ajustes negativos y a
     * la cuenta origen de una transferencia. Al correr dentro de la transacción con la cuenta ya
     * bloqueada, el saldo contra el que se compara no puede cambiar entre la validación y la
     * escritura; y al lanzar `ValidationException` se revierte todo lo escrito.
     */
    private function recalcularYValidar(Cuenta $cuenta): void
    {
        $this->recalcularSaldo($cuenta);

        if ((float) $cuenta->saldo_actual < -self::EPSILON) {
            throw ValidationException::withMessages([
                'monto' => 'El movimiento dejaría la cuenta con saldo negativo',
            ]);
        }
    }
}
