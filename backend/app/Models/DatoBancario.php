<?php

namespace App\Models;

use Database\Factories\DatoBancarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Cuenta bancaria que se le imprime al cliente en la cotización para que pague
 * (ver 026-datos-bancarios-cotizacion.md).
 *
 * No tiene nada que ver con `Cuenta` (010-tesoreria.md): aquélla guarda dinero y saldo, ésta solo
 * se imprime. No se relacionan en el esquema a propósito.
 *
 * `$table` explícito porque Eloquent inferiría `dato_bancarios` — misma lección ya pagada en 005,
 * 008, 012, 017 y 019.
 */
#[Fillable([
    'nombre_banco',
    'beneficiario',
    'numero_cuenta',
    'tarjeta',
    'clabe',
    'visible_en_cotizaciones',
])]
class DatoBancario extends Model
{
    /** @use HasFactory<DatoBancarioFactory> */
    use HasFactory;

    protected $table = 'datos_bancarios';

    /** Directorio del disco privado donde viven los iconos. */
    public const DIRECTORIO_LOGOS = 'datos-bancarios';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'visible_en_cotizaciones' => true,
    ];

    /**
     * Los 8 caracteres al azar del nombre del archivo, o `null` si no hay logo.
     *
     * El frontend los pega a la dirección del icono (`?v=...`), de modo que reemplazar un logo
     * cambia la dirección y el navegador va por el nuevo sin que nadie vacíe su caché — mismo
     * mecanismo que `imagen_version` en 020-imagenes-articulos.md.
     */
    public function logoVersion(): ?string
    {
        if (! filled($this->logo_ruta)) {
            return null;
        }

        return pathinfo((string) $this->logo_ruta, PATHINFO_FILENAME);
    }

    /**
     * Contenido binario del logo, o `null` si no hay o el archivo ya no está en disco.
     *
     * Mismo criterio que `Emisor::contenidoLogo` en 019: la ausencia del archivo se distingue de
     * "no hay logo" y quien llama decide qué hacer con el `null`.
     */
    public function contenidoLogo(): ?string
    {
        return static::contenidoLogoDe($this->logo_ruta);
    }

    /**
     * El logo de una ruta cualquiera, para poder leer también las rutas congeladas dentro de una
     * cotización, que ya no tienen modelo detrás.
     */
    public static function contenidoLogoDe(?string $ruta): ?string
    {
        if (! filled($ruta) || ! Storage::disk('local')->exists($ruta)) {
            return null;
        }

        return Storage::disk('local')->get($ruta);
    }

    /**
     * Los que se imprimen, en el orden en que se imprimen. `orden` puede repetirse si alguien tocó
     * la tabla a mano, así que el `id` desempata y la lista nunca sale en orden distinto entre dos
     * consultas iguales.
     *
     * @param  Builder<DatoBancario>  $query
     */
    #[Scope]
    protected function visibles(Builder $query): void
    {
        $query->where('visible_en_cotizaciones', true)->orderBy('orden')->orderBy('id');
    }

    /**
     * Posición que le toca a un banco nuevo: al final de la lista.
     */
    public static function siguienteOrden(): int
    {
        return ((int) static::query()->max('orden')) + 1;
    }

    /**
     * La foto que se guarda dentro de la cotización al crearla.
     *
     * Solo los campos que se imprimen: ni `id`, ni el interruptor, ni el orden. Guardar el `id`
     * invitaría a releer el banco vigente desde la foto, que es exactamente lo que el congelado
     * existe para impedir.
     *
     * Del logo se guarda la **ruta**, no la imagen: meter el WEBP en base64 aquí pondría una copia
     * del mismo icono en cada cotización —varias por documento si hay varios bancos— cuando el
     * archivo al que apunta ya no cambia nunca. Reemplazar un logo escribe un archivo nuevo con
     * otro nombre, y eliminar el banco no borra el viejo.
     *
     * @return array<int, array<string, string|null>>
     */
    public static function fotoParaCotizacion(): array
    {
        /** @var Collection<int, DatoBancario> $bancos */
        $bancos = static::query()->visibles()->get();

        return $bancos->map(fn (DatoBancario $banco) => [
            'nombre_banco' => $banco->nombre_banco,
            'beneficiario' => $banco->beneficiario,
            'numero_cuenta' => $banco->numero_cuenta,
            'tarjeta' => $banco->tarjeta,
            'clabe' => $banco->clabe,
            'logo_ruta' => $banco->logo_ruta,
        ])->all();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visible_en_cotizaciones' => 'boolean',
        ];
    }
}
