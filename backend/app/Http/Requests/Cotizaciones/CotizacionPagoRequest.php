<?php

namespace App\Http\Requests\Cotizaciones;

use App\Enums\TipoPagoCotizacion;
use App\Models\Cotizacion;
use App\Rules\CuentaActivaDelUsuario;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Solo `tipo = anticipo` acepta un `monto` libre; para `saldo`/`pago_total` el controller siempre
 * lo autocalcula como el saldo pendiente, ignorando cualquier valor enviado (ver
 * 008-cotizaciones.md).
 */
class CotizacionPagoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Cotizacion $cotizacion */
        $cotizacion = $this->route('cotizacion');

        return [
            'tipo' => ['required', 'in:anticipo,saldo,pago_total', $this->sinAnticipoPrevio($cotizacion)],
            'fecha_pago' => ['required', 'date'],
            'monto' => [
                Rule::requiredIf($this->input('tipo') === 'anticipo'),
                'nullable',
                'numeric',
                'gt:0',
                'decimal:0,2',
                $this->sinSobrepago($cotizacion),
            ],
            // Reemplaza a la antigua regla de `forma_pago` (catálogo SAT): el pago de una
            // cotización entra a una cuenta de Tesorería, y esa cuenta debe estar activa porque
            // el pago genera un movimiento automático (ver 010-tesoreria.md).
            'cuenta_id' => ['required', 'integer', new CuentaActivaDelUsuario($this->user()->id)],
        ];
    }

    /**
     * Una cotización admite como máximo un pago `tipo = anticipo`.
     */
    private function sinAnticipoPrevio(Cotizacion $cotizacion): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($cotizacion) {
            if ($value === TipoPagoCotizacion::Anticipo->value
                && $cotizacion->pagos()->where('tipo', TipoPagoCotizacion::Anticipo->value)->exists()) {
                $fail('Esta cotización ya tiene un anticipo registrado.');
            }
        };
    }

    /**
     * Solo aplica al monto libre de `anticipo`; `saldo`/`pago_total` siempre se autocalculan
     * exactamente al saldo pendiente, por lo que nunca pueden sobrepagar.
     */
    private function sinSobrepago(Cotizacion $cotizacion): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($cotizacion) {
            if ($this->input('tipo') !== TipoPagoCotizacion::Anticipo->value) {
                return;
            }

            $saldoPendiente = max(0, (float) $cotizacion->total - $cotizacion->totalPagado());
            if ((float) $value > $saldoPendiente) {
                $fail('El monto no puede superar el saldo pendiente de la cotización.');
            }
        };
    }
}
