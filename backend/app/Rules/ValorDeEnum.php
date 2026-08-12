<?php

namespace App\Rules;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Valida un valor contra un enum backed y, al fallar, dice qué columna falló, qué valor llegó y
 * cuáles se admiten (ver 006-gestion-articulos.md).
 *
 * Existe para la importación CSV, donde el mensaje del framework ("The selected objeto imp is
 * invalid") se repite en cada fila sin mencionar el valor recibido: con decenas de filas iguales,
 * ese dato es lo único que permite dar con la causa en la hoja de cálculo de origen.
 */
class ValorDeEnum implements ValidationRule
{
    /** Recorte del valor recibido en el mensaje: una celda puede traer un texto arbitrariamente largo. */
    private const LARGO_MAXIMO = 30;

    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private readonly string $enum) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $this->enum::tryFrom($value) !== null) {
            return;
        }

        $fail(sprintf(
            '%s "%s" no es un valor válido (%s).',
            $attribute,
            Str::limit(is_scalar($value) ? trim((string) $value) : '', self::LARGO_MAXIMO),
            implode(', ', array_column($this->enum::cases(), 'value')),
        ));
    }
}
