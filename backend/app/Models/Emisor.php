<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Datos fiscales del negocio que emite los documentos (ver 019-formato-pdf-documentos.md).
 *
 * Fila única para toda la instalación. `$table` explícito porque Eloquent pluralizaría en inglés
 * y de `Emisor` inferiría `emisors` — misma lección ya pagada en 005, 008, 012 y 017.
 */
class Emisor extends Model
{
    protected $table = 'emisor';

    protected $fillable = [
        'nombre',
        'rfc',
        'regimen_fiscal',
        'domicilio',
        'correo',
        'telefono',
        'logo_ruta',
        'logo_marca_ruta',
    ];

    /**
     * Directorio del disco privado donde viven los logos.
     */
    public const DIRECTORIO_LOGOS = 'emisor';

    /**
     * El emisor de la instalación. **Nunca devuelve `null`**: si nadie lo ha capturado devuelve una
     * instancia vacía sin guardar, para que ni la plantilla del PDF ni el frontend tengan que
     * preguntar si existe.
     */
    public static function actual(): self
    {
        return static::query()->orderBy('id')->first() ?? new static;
    }

    /**
     * Un emisor está completo cuando trae lo mínimo que un documento necesita para identificar a
     * quien lo emite. Domicilio, correo, teléfono y logos son opcionales.
     */
    public function estaCompleto(): bool
    {
        return filled($this->nombre) && filled($this->rfc) && filled($this->regimen_fiscal);
    }

    /**
     * Contenido binario de un logo, o `null` si no hay logo o el archivo ya no está en disco.
     *
     * Quien la llama decide qué hacer con el `null`; la plantilla imprime el hueco vacío y deja
     * un aviso en el log, porque un PDF nunca debe fallar por un logo.
     */
    public function contenidoLogo(string $tipo): ?string
    {
        $ruta = $tipo === 'marca' ? $this->logo_marca_ruta : $this->logo_ruta;

        if (! filled($ruta)) {
            return null;
        }

        if (! Storage::disk('local')->exists($ruta)) {
            // Hay logo capturado pero el archivo ya no está. Es distinto de "no hay logo" y no
            // puede pasar en silencio: el usuario vería el hueco sin saber por qué.
            Log::warning('Falta en disco el logo del emisor.', ['tipo' => $tipo, 'ruta' => $ruta]);

            return null;
        }

        return Storage::disk('local')->get($ruta);
    }

    /**
     * El logo listo para incrustarse en el HTML del PDF, o `null` si no hay.
     *
     * Se incrusta en base64 y no se referencia por ruta ni por URL: dompdf resolvería la ruta
     * relativa contra `public_path`, que en producción apunta a un directorio que no existe (ver
     * 018-despliegue-hostinger.md y el comentario de config/dompdf.php).
     */
    public function logoBase64(string $tipo): ?string
    {
        $contenido = $this->contenidoLogo($tipo);

        if ($contenido === null) {
            return null;
        }

        $mime = str_starts_with($contenido, "\x89PNG") ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($contenido);
    }
}
