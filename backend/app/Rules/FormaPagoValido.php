<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use PhpCfdi\SatCatalogos\SatCatalogos;

class FormaPagoValido implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SatCatalogos::class)->formasDePago40()->exists($value)) {
            $fail('El campo :attribute no corresponde a una forma de pago válida del catálogo SAT.');
        }
    }
}
