<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * CLABE de 18 dígitos con dígito verificador correcto (ver 026-datos-bancarios-cotizacion.md).
 *
 * El dígito 18 no es libre: sale de una cuenta hecha con los 17 anteriores. Comprobarlo es la única
 * forma que tiene el sistema de atrapar un dedo chueco antes de que la CLABE salga impresa en una
 * cotización y un cliente mande dinero a ninguna parte — la longitud correcta no dice nada, y el
 * banco no avisa hasta que la transferencia ya se intentó.
 */
class ClabeValida implements ValidationRule
{
    /**
     * Pesos que se aplican a los 17 primeros dígitos, ciclando.
     *
     * @var array<int, int>
     */
    private const PESOS = [3, 7, 1];

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^\d{18}$/', $value) !== 1) {
            $fail('La CLABE debe tener exactamente 18 dígitos.');

            return;
        }

        if ((int) $value[17] !== self::digitoVerificador($value)) {
            $fail('La CLABE no es válida: revisa que no falte ni sobre un dígito.');
        }
    }

    /**
     * Dígito verificador que le corresponde a una CLABE por sus 17 primeros dígitos.
     *
     * De cada producto se toma solo su última cifra (`% 10`) antes de sumar; ésa es la parte del
     * algoritmo que se olvida y hace que una implementación "casi correcta" acepte CLABEs malas.
     */
    public static function digitoVerificador(string $clabe): int
    {
        $suma = 0;

        for ($i = 0; $i < 17; $i++) {
            $suma += ((int) $clabe[$i] * self::PESOS[$i % 3]) % 10;
        }

        return (10 - ($suma % 10)) % 10;
    }
}
