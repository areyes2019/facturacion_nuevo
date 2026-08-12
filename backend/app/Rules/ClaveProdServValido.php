<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;
use PhpCfdi\SatCatalogos\SatCatalogos;

class ClaveProdServValido implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! app(SatCatalogos::class)->productosServicios40()->exists($value)) {
            $fail('El campo :attribute no corresponde a una clave de producto/servicio válida del catálogo SAT.');
        }
    }
}
