<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use PhpCfdi\SatCatalogos\SatCatalogos;

class CodigoPostalValido implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SatCatalogos::class)->codigosPostales40()->exists($value)) {
            $fail('El campo :attribute no corresponde a un código postal existente en el catálogo SAT.');
        }
    }
}
