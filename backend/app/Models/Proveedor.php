<?php

namespace App\Models;

use App\Enums\EstadoOrdenCompra;
use Database\Factories\ProveedorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'nombre_comercial',
    'nombre_contacto',
    'correo',
    'telefono',
    'rfc',
])]
class Proveedor extends Model
{
    /** @use HasFactory<ProveedorFactory> */
    use HasFactory, SoftDeletes;

    protected $table = 'proveedores';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogos(): HasMany
    {
        return $this->hasMany(Catalogo::class);
    }

    public function ordenesCompra(): HasMany
    {
        return $this->hasMany(OrdenCompra::class);
    }

    /**
     * Bloquea el borrado del proveedor mientras tenga al menos una orden de compra sin cerrar su
     * ciclo (cualquier estado distinto de `recibida`, incluido `borrador`).
     *
     * 005 sembró esto como columna booleana esperando al módulo de Órdenes de compra; ahora que
     * existe, se **deriva por consulta** en vez de mantener una columna sincronizada a mano en cada
     * alta, cambio de estado y borrado de orden (ver 012-ordenes-compra.md, adición técnica 37).
     */
    public function tieneOrdenesActivas(): bool
    {
        return $this->ordenesCompra()
            ->where('estado', '!=', EstadoOrdenCompra::Recibida->value)
            ->exists();
    }
}
