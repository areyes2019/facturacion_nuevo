<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use PhpCfdi\Rfc\Rfc;

/**
 * Valida solo la estructura del RFC (longitud, patrón, homoclave), no lo verifica contra el
 * webservice del SAT. Acepta RFC de persona física, persona moral y los genéricos
 * (XAXX010101000 / XEXX010101000).
 */
class RfcValido implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Rfc::parseOrNull($value) === null) {
            $fail('El campo :attribute no tiene un formato de RFC válido.');
        }
    }
}
