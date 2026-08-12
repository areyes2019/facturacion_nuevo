<?php

namespace App\Models;

use Database\Factories\ClienteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use PhpCfdi\Rfc\Rfc;

#[Fillable([
    'rfc',
    'razon_social',
    'regimen_fiscal',
    'codigo_postal_fiscal',
    'nombre_comercial',
    'correo_contacto',
    'telefono',
    'direccion_comercial',
    'descuento_permanente',
])]
class Cliente extends Model
{
    /** @use HasFactory<ClienteFactory> */
    use HasFactory, SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'descuento_permanente' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tipo de persona (Física/Moral), inferido de la longitud del RFC.
     * No se persiste como columna.
     */
    protected function tipoPersona(): Attribute
    {
        return Attribute::make(
            get: function (): ?string {
                $rfc = Rfc::parseOrNull((string) $this->rfc);

                if ($rfc === null || $rfc->isGeneric() || $rfc->isForeign()) {
                    return null;
                }

                if ($rfc->isFisica()) {
                    return 'fisica';
                }

                if ($rfc->isMoral()) {
                    return 'moral';
                }

                return null;
            },
        )->shouldCache();
    }
}
