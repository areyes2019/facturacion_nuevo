<?php

namespace App\Http\Requests\DatosBancarios;

use App\Rules\ClabeValida;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Alta y edición de una cuenta bancaria del negocio (ver 026-datos-bancarios-cotizacion.md).
 *
 * Un solo request para las dos: las reglas son idénticas y no hay unicidad que dependa del registro
 * que se está editando (dos cuentas en el mismo banco son dos renglones legítimos).
 */
class GuardarDatoBancarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Se limpian espacios y guiones de los tres números antes de validar, para que pegar
     * `4152 3133 1234 5678` tal como lo muestra la banca en línea funcione sin obligar al usuario a
     * borrar los separadores a mano. Lo que se guarda son solo los dígitos.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'numero_cuenta' => $this->soloDigitos($this->input('numero_cuenta')),
            'tarjeta' => $this->soloDigitos($this->input('tarjeta')),
            'clabe' => $this->soloDigitos($this->input('clabe')),
        ]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nombre_banco' => ['required', 'string', 'max:100'],
            'beneficiario' => ['nullable', 'string', 'max:150'],
            'numero_cuenta' => ['nullable', 'string', 'regex:/^\d{6,20}$/'],
            // 15 dígitos cubre American Express; 16 el resto.
            'tarjeta' => ['nullable', 'string', 'regex:/^\d{15,16}$/'],
            'clabe' => ['nullable', 'string', new ClabeValida],
            'visible_en_cotizaciones' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'numero_cuenta.regex' => 'El número de cuenta debe tener entre 6 y 20 dígitos.',
            'tarjeta.regex' => 'La tarjeta debe tener 15 o 16 dígitos.',
        ];
    }

    /**
     * Un banco sin ningún número no le sirve al cliente, que es la única razón por la que este
     * registro existe.
     *
     * El error se cuelga del formulario entero (`datos_bancarios`) y no de uno de los tres campos:
     * no es culpa de ninguno en particular, y señalar al primero mandaría al usuario a llenar la
     * cuenta cuando quizá lo que quería capturar era la CLABE.
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $sinNumeros = blank($this->input('numero_cuenta'))
                    && blank($this->input('tarjeta'))
                    && blank($this->input('clabe'));

                if ($sinNumeros) {
                    $validator->errors()->add(
                        'datos_bancarios',
                        'Captura al menos un número de cuenta, tarjeta o CLABE.',
                    );
                }
            },
        ];
    }

    /**
     * `null` y no `''` cuando no queda nada: la columna es nullable y un texto vacío haría que
     * `blank()` y la plantilla del PDF tuvieran que distinguir dos formas del mismo hueco.
     */
    private function soloDigitos(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return $digitos === '' ? null : $digitos;
    }
}
