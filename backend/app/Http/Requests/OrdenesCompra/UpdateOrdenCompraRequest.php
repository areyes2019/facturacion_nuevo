<?php

namespace App\Http\Requests\OrdenesCompra;

/**
 * Mismas reglas que el alta: la orden es libremente editable mientras no esté pagada, incluido el
 * proveedor y todas sus líneas (ver 012-ordenes-compra.md, supuesto #8). Quién puede editarla y en
 * qué estado lo verifica el controller, no este Form Request.
 */
class UpdateOrdenCompraRequest extends StoreOrdenCompraRequest {}
