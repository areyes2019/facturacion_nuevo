<?php

namespace App\Enums;

/**
 * Claves admitidas del almacén de configuración global (ver 014-costo-elaboracion-goma.md).
 *
 * El almacén es clave→valor para que los ajustes futuros (tasa de IVA, datos fiscales del emisor,
 * folio inicial) entren sin tabla nueva, pero la lista de claves es cerrada: una clave fuera de este
 * enum se rechaza con 422 y no crea fila. El pizarrón no se llena de renglones que nadie lee.
 *
 * Cada caso es dueño de su valor por defecto y de sus reglas de validación, porque el valor se
 * persiste como texto y el tipo no lo impone la columna.
 */
enum ClaveConfiguracion: string
{
    case CostoGomaChica = 'costo_goma_chica';
    case CostoGomaMediana = 'costo_goma_mediana';
    case CostoGomaGrande = 'costo_goma_grande';
    case CostoGomaJumbo = 'costo_goma_jumbo';
    case MensajeTicket = 'mensaje_ticket';
    case MensajeListo = 'mensaje_listo';
    case EnvioTarifaA = 'envio_tarifa_a';
    case EnvioTarifaB = 'envio_tarifa_b';
    case EnvioTarifaC = 'envio_tarifa_c';

    /**
     * Valor con el que arranca un usuario que nunca ha guardado la pantalla de Configuración.
     *
     * Cumple tres papeles y por eso no hace falta un cuarto mecanismo: es el valor de fábrica, es
     * lo que devuelve la lectura cuando no hay fila, y es la red si una fila se borra a mano.
     */
    public function valorPorDefecto(): string
    {
        return match ($this) {
            self::CostoGomaChica => '6.00',
            self::CostoGomaMediana => '10.00',
            self::CostoGomaGrande => '20.00',
            self::CostoGomaJumbo => '40.00',
            self::MensajeTicket => self::MENSAJE_TICKET_DE_FABRICA,
            self::MensajeListo => self::MENSAJE_LISTO_DE_FABRICA,
            self::EnvioTarifaA => '50.00',
            self::EnvioTarifaB => '80.00',
            self::EnvioTarifaC => '120.00',
        };
    }

    /**
     * Reglas de validación del valor de esta clave, **incluida su obligatoriedad**.
     *
     * Los costos se permiten en 0.00 (una categoría que no cuesta nada) pero nunca negativos,
     * porque una goma que abarate el artículo no existe. Los dos mensajes, en cambio, son claves de
     * texto y sí admiten quedar vacíos: el usuario puede no querer mandar nada junto con la imagen,
     * o no querer avisar. Por eso `required` vive aquí y no en el controlador — la regla depende de
     * la clave, no del endpoint.
     *
     * @return array<int, string>
     */
    public function reglas(): array
    {
        return match ($this) {
            self::CostoGomaChica,
            self::CostoGomaMediana,
            self::CostoGomaGrande,
            self::CostoGomaJumbo => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
            // `nullable` además de `present` porque Laravel convierte la cadena vacía en null antes
            // de validar (`ConvertEmptyStringsToNull`): sin él, vaciar el campo se leería como "no
            // mandaste un texto" en vez de "no quiero mensaje".
            self::MensajeTicket,
            self::MensajeListo => ['present', 'nullable', 'string', 'max:2000'],
            self::EnvioTarifaA,
            self::EnvioTarifaB,
            self::EnvioTarifaC => ['required', 'numeric', 'gte:0', 'decimal:0,2'],
        };
    }

    /**
     * Huecos que `MensajePedidoService` rellena antes de compartir, comunes a los dos mensajes. El
     * frontend los lista debajo de cada campo, así que la lista vive una sola vez y aquí.
     *
     * @return array<int, string>
     */
    public static function huecosMensajeTicket(): array
    {
        return ['nombre', 'folio', 'total', 'pagado', 'saldo'];
    }

    /**
     * Mensaje de fábrica que acompaña al ticket, dictado por el usuario en
     * 027-venta-mostrador-ticket.md.
     */
    private const MENSAJE_TICKET_DE_FABRICA = <<<'TXT'
        ¿Qué sigue ahora?
        😊 Te explico los siguientes pasos:

        1️⃣ Primero trabajaremos en el diseño de tu sello. ✍️ Te enviaremos una propuesta para que la revises y nos confirmes si estás de acuerdo. 👀

        2️⃣ Una vez aprobado, comenzamos la producción. El tiempo estimado de entrega es de 24 a 48 horas hábiles. ⏳

        Por ejemplo, si apruebas el diseño un viernes, tu sello estará listo para el lunes. 📅

        Cuando esté terminado, podrás elegir cómo recibirlo:
        📍 Recogerlo personalmente o
        🚚 Recibirlo por paquetería, directo a tu domicilio.

        ¿Te parece bien? Quedo atento(a) a tu confirmación. 😉
        TXT;

    /**
     * Aviso de que el pedido ya se puede recoger (ver 027-venta-mostrador-ticket.md).
     *
     * Editable y no fijo en el código, a diferencia de lo que podría parecer por lo corto: es un
     * mensaje que se le manda al cliente y el tono lo pone el negocio, no el sistema.
     */
    private const MENSAJE_LISTO_DE_FABRICA = <<<'TXT'
        Hola {nombre} 👋
        Tu pedido con No. de ticket {folio} ya está listo. 🎉
        Puedes pasar por él cuando gustes. ¡Gracias por tu preferencia!
        TXT;

    /**
     * Valores de fábrica de todas las claves, indexados por clave.
     *
     * @return array<string, string>
     */
    public static function valoresPorDefecto(): array
    {
        $valores = [];

        foreach (self::cases() as $caso) {
            $valores[$caso->value] = $caso->valorPorDefecto();
        }

        return $valores;
    }
}
