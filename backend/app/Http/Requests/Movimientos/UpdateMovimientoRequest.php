<?php

namespace App\Http\Requests\Movimientos;

/**
 * Mismas reglas que el alta: la edición de un movimiento manual puede cambiar cualquiera de sus
 * campos, incluida la cuenta. Los movimientos automáticos ni siquiera llegan aquí — el controller
 * los rechaza con 422 antes (ver 010-tesoreria.md).
 */
class UpdateMovimientoRequest extends StoreMovimientoRequest {}
