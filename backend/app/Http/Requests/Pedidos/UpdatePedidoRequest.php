<?php

namespace App\Http\Requests\Pedidos;

/**
 * Mismas reglas que el alta: editar un pedido reescribe cliente y líneas completas. El bloqueo por
 * estado (solo `pendiente`/`anticipo`) vive en el controlador, porque depende del pedido que trae
 * la ruta y no de lo que se envía.
 */
class UpdatePedidoRequest extends StorePedidoRequest {}
