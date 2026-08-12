<?php

namespace App\Services;

use App\Enums\ClaveConfiguracion;
use App\Enums\TamanoGoma;
use App\Models\User;

/**
 * Lectura y escritura del almacén de configuración global (ver 014-costo-elaboracion-goma.md).
 *
 * Se registra como singleton y memoiza los ajustes del usuario en la primera lectura de cada
 * petición: un recálculo en bloque sobre cientos de artículos hace una consulta, no una por
 * artículo.
 */
class ConfiguracionService
{
    /**
     * Ajustes ya leídos en esta petición, por id de usuario.
     *
     * @var array<int, array<string, string>>
     */
    private array $memo = [];

    /**
     * Todos los ajustes del usuario con su valor efectivo: el guardado o, si no hay fila, el de
     * fábrica. Nunca devuelve un mapa incompleto, para que quien lo consuma no tenga que saber qué
     * es un valor por defecto.
     *
     * @return array<string, string>
     */
    public function todos(User $user): array
    {
        return $this->memo[$user->id] ??= [
            ...ClaveConfiguracion::valoresPorDefecto(),
            ...$user->configuraciones()->pluck('valor', 'clave')->all(),
        ];
    }

    public function obtener(User $user, ClaveConfiguracion $clave): string
    {
        return $this->todos($user)[$clave->value];
    }

    /**
     * Costo vigente de la goma de un tamaño dado. Sin tamaño no hay goma, y por lo tanto no hay
     * costo que sumar.
     */
    public function costoGoma(User $user, ?TamanoGoma $tamano): float
    {
        if ($tamano === null) {
            return 0.0;
        }

        return (float) $this->obtener($user, $tamano->claveConfiguracion());
    }

    /**
     * Persiste los valores recibidos, indexados por clave. Las claves ausentes se dejan como
     * estaban.
     *
     * @param  array<string, string|float|int>  $valores
     */
    public function guardar(User $user, array $valores): void
    {
        foreach ($valores as $clave => $valor) {
            $user->configuraciones()->updateOrCreate(
                ['clave' => $clave],
                ['valor' => (string) $valor],
            );
        }

        unset($this->memo[$user->id]);
    }
}
