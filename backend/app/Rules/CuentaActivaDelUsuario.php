<?php

namespace App\Rules;

use App\Models\Cuenta;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * La cuenta debe existir, pertenecer al usuario autenticado y estar activa: una cuenta inactiva
 * conserva su historial pero no admite movimientos nuevos de ningún tipo, ni manuales ni
 * automáticos (ver 010-tesoreria.md).
 */
class CuentaActivaDelUsuario implements ValidationRule
{
    public function __construct(private readonly int $userId) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $cuenta = Cuenta::where('user_id', $this->userId)->find($value);

        if ($cuenta === null) {
            $fail('La cuenta seleccionada no existe.');

            return;
        }

        if (! $cuenta->activa) {
            $fail('La cuenta seleccionada está inactiva y no admite movimientos nuevos.');
        }
    }
}
