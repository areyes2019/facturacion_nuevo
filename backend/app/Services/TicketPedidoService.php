<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\Pedido;
use GdImage;
use RuntimeException;

/**
 * Dibuja el ticket de compra de un pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * **Lo dibuja el servidor y no el navegador.** El mismo pedido se comparte desde la computadora del
 * mostrador y desde el celular del usuario; si cada aparato dibujara la imagen con sus propias
 * fuentes —y su propio código QR— al cliente le llegarían tickets de distinto aspecto según desde
 * dónde se mandó.
 *
 * **No se guarda en ningún lado.** Se dibuja cada vez que se pide y se desecha. La imagen no es un
 * registro: el folio, el cliente, las líneas, los totales y los pagos viven en la base de datos y
 * esto solo los pinta, así que se puede volver a dibujar idéntica cuando haga falta. Guardarla
 * sería guardar una copia por comodidad, y a diez tickets diarios eso son ~200 MB al año que nunca
 * dejan de crecer en un plan compartido donde el espacio se paga.
 *
 * Tiene dos consecuencias buenas y una mala, todas asumidas: es **imposible** que el ticket muestre
 * un saldo viejo —desaparece toda la lógica de invalidarlo al registrar o borrar un pago—, no hay
 * nada que limpiar después, y a cambio compartirlo dos veces lo dibuja dos veces, que son
 * milésimas de segundo.
 */
class TicketPedidoService
{
    /** Ancho útil de una impresora térmica de 80 mm a 203 dpi. */
    private const ANCHO = 576;

    private const MARGEN = 24;

    private const TAM_TITULO = 26;

    private const TAM_NORMAL = 16;

    private const TAM_CHICO = 14;

    /** Lado máximo del logo dentro del ticket. */
    private const LADO_LOGO = 240;

    /**
     * Lado del QR dentro del ticket.
     *
     * Poco más de la mitad del ancho, y a propósito: el peor escenario de este código es que se lea
     * **desde la pantalla de un celular**, que brilla y refleja. 300 px sobran para eso y para un
     * papel maltratado.
     */
    private const LADO_QR = 300;

    /**
     * Zona horaria del negocio (mono-usuario/mono-empresa): la hora impresa en el ticket es la del
     * mostrador, no la de UTC en que se guardó `created_at`. Mismo criterio que el listado de
     * cotizaciones en 008.
     */
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    public function __construct(private readonly QrTimbreFiscal $qr) {}

    /**
     * Dibuja el ticket y devuelve los bytes del JPEG. Dos pasadas sobre la misma lista de filas: la
     * primera mide el alto total, la segunda pinta. Sin medir antes no hay forma de crear el lienzo
     * del tamaño correcto, y un lienzo fijo dejaría tickets largos cortados o tickets cortos con
     * medio metro de blanco abajo.
     */
    public function contenido(Pedido $pedido): string
    {
        $pedido->loadMissing('lineas');

        $filas = $this->filas($pedido);
        $alto = self::MARGEN * 2;

        foreach ($filas as $fila) {
            $alto += $fila['alto'];
        }

        $lienzo = imagecreatetruecolor(self::ANCHO, $alto);

        if ($lienzo === false) {
            throw new RuntimeException('No se pudo crear el lienzo del ticket.');
        }

        $blanco = (int) imagecolorallocate($lienzo, 255, 255, 255);
        $negro = (int) imagecolorallocate($lienzo, 0, 0, 0);
        imagefilledrectangle($lienzo, 0, 0, self::ANCHO, $alto, $blanco);

        $y = self::MARGEN;

        foreach ($filas as $fila) {
            $this->pintar($lienzo, $fila, $y, $negro);
            $y += $fila['alto'];
        }

        ob_start();
        imagejpeg($lienzo, null, 85);
        $binario = (string) ob_get_clean();
        imagedestroy($lienzo);

        return $binario;
    }

    /**
     * La lista de filas del ticket, en el orden en que se imprimen.
     *
     * @return array<int, array<string, mixed>>
     */
    private function filas(Pedido $pedido): array
    {
        $emisor = Emisor::actual();
        $filas = [];

        $logo = $this->logo($emisor);
        if ($logo !== null) {
            $filas[] = ['tipo' => 'imagen', 'imagen' => $logo, 'alto' => imagesy($logo) + 16];
        }

        if (filled($emisor->nombre)) {
            $filas[] = $this->texto((string) $emisor->nombre, self::TAM_NORMAL, 'c', negrita: true);
        }
        if (filled($emisor->domicilio)) {
            foreach ($this->partir((string) $emisor->domicilio, self::TAM_CHICO, false) as $renglon) {
                $filas[] = $this->texto($renglon, self::TAM_CHICO, 'c');
            }
        }
        if (filled($emisor->telefono)) {
            $filas[] = $this->texto('Tel. '.$emisor->telefono, self::TAM_CHICO, 'c');
        }

        $filas[] = $this->separador();
        $filas[] = $this->texto('TICKET No. '.$pedido->numeroTicket(), self::TAM_TITULO, 'c', negrita: true);
        $filas[] = $this->texto(
            ($pedido->created_at ?? now())->timezone(self::ZONA_HORARIA_NEGOCIO)->format('d/m/Y H:i'),
            self::TAM_CHICO,
            'c',
        );
        $filas[] = $this->texto('Cliente: '.$pedido->cliente_nombre, self::TAM_NORMAL, 'l');
        $filas[] = $this->separador();

        foreach ($pedido->lineas as $linea) {
            foreach ($this->partir((string) $linea->descripcion, self::TAM_NORMAL, false) as $renglon) {
                $filas[] = $this->texto($renglon, self::TAM_NORMAL, 'l');
            }

            // El precio va CON IVA: es el que el cliente conoce y el único con el que puede
            // comprobar el total que lee abajo (ver 030-total-al-peso-cerrado.md). Con el precio
            // sin IVA, la multiplicación del renglón no daba su propio importe.
            $precioConIva = round((float) $linea->precio_unitario * (1 + $linea->tasa_iva->tasa()), 2);

            $filas[] = $this->par(
                $linea->cantidad.' x '.$this->dinero($precioConIva),
                $this->dinero((float) $linea->importe + (float) $linea->iva_importe),
                self::TAM_CHICO,
            );
        }

        $filas[] = $this->separador();

        $filas[] = $this->par('Subtotal', $this->dinero((float) $pedido->subtotal), self::TAM_CHICO);
        if ((float) $pedido->total_descuento > 0) {
            $filas[] = $this->par('Descuento', '-'.$this->dinero((float) $pedido->total_descuento), self::TAM_CHICO);
        }
        $filas[] = $this->par('IVA', $this->dinero((float) $pedido->total_iva_16), self::TAM_CHICO);
        if ((float) $pedido->ajuste_al_peso > 0) {
            $filas[] = $this->par('Ajuste al peso', $this->dinero((float) $pedido->ajuste_al_peso), self::TAM_CHICO);
        }
        $filas[] = $this->par('TOTAL', $this->dinero((float) $pedido->total), self::TAM_NORMAL, negrita: true);

        $filas[] = $this->separador();
        $filas[] = $this->par('Pagado', $this->dinero($pedido->totalPagado()), self::TAM_NORMAL);
        $filas[] = $this->par('Saldo pendiente', $this->dinero($pedido->saldoPendiente()), self::TAM_NORMAL, negrita: true);

        $filas[] = $this->separador();
        $filas[] = $this->texto('¡Gracias por tu compra!', self::TAM_CHICO, 'c');

        // El QR al final, que es donde el ojo termina de leer la tira y donde no compite con nada.
        // El número impreso debajo es el respaldo para cuando el código no lee y hay que buscar el
        // pedido a mano.
        $codigo = $this->codigoQr($pedido);
        if ($codigo !== null) {
            $filas[] = ['tipo' => 'imagen', 'imagen' => $codigo, 'alto' => imagesy($codigo) + 16];
            $filas[] = $this->texto('No. '.$pedido->numeroTicket(), self::TAM_CHICO, 'c');
        }

        return $filas;
    }

    /**
     * El QR de la pantalla de entrega, ya al tamaño exacto, o `null` si no se pudo dibujar.
     *
     * Se pide en grande y se reduce con **vecino más cercano**: cualquier interpolación suaviza los
     * bordes de los módulos y un código borroso es un código que el lector rechaza. Un ticket nunca
     * falla por su QR —mismo criterio que el logo—: si algo sale mal, sale sin código y con el
     * número de ticket, que es con lo que se busca el pedido a mano.
     */
    private function codigoQr(Pedido $pedido): ?GdImage
    {
        $png = $this->qr->imagenPng($pedido->urlEntrega(), escala: 10);

        if ($png === null) {
            return null;
        }

        $original = @imagecreatefromstring($png);

        if ($original === false) {
            return null;
        }

        $reducido = imagescale($original, self::LADO_QR, self::LADO_QR, IMG_NEAREST_NEIGHBOUR);
        imagedestroy($original);

        return $reducido === false ? null : $reducido;
    }

    /**
     * El logo del emisor ya reducido, o `null` si no hay o no se pudo leer. Un ticket nunca falla
     * por una imagen — mismo criterio que el logo del PDF en 019.
     */
    private function logo(Emisor $emisor): ?GdImage
    {
        $contenido = $emisor->contenidoLogo('principal');

        if ($contenido === null) {
            return null;
        }

        $original = @imagecreatefromstring($contenido);

        if ($original === false) {
            return null;
        }

        $escala = min(1, self::LADO_LOGO / max(imagesx($original), 1));
        $ancho = max(1, (int) round(imagesx($original) * $escala));
        $alto = max(1, (int) round(imagesy($original) * $escala));

        $reducido = imagescale($original, $ancho, $alto);
        imagedestroy($original);

        return $reducido === false ? null : $reducido;
    }

    /**
     * @param  array<string, mixed>  $fila
     */
    private function pintar(GdImage $lienzo, array $fila, int $y, int $negro): void
    {
        if ($fila['tipo'] === 'espacio') {
            return;
        }

        if ($fila['tipo'] === 'imagen') {
            /** @var GdImage $imagen */
            $imagen = $fila['imagen'];
            $x = (int) ((self::ANCHO - imagesx($imagen)) / 2);
            imagecopy($lienzo, $imagen, $x, $y, 0, 0, imagesx($imagen), imagesy($imagen));

            return;
        }

        if ($fila['tipo'] === 'separador') {
            $mitad = $y + (int) ($fila['alto'] / 2);
            imageline($lienzo, self::MARGEN, $mitad, self::ANCHO - self::MARGEN, $mitad, $negro);

            return;
        }

        $fuente = $this->fuente((bool) $fila['negrita']);
        $tam = (int) $fila['tam'];
        $base = $y + $tam;

        if ($fila['tipo'] === 'par') {
            imagettftext($lienzo, $tam, 0, self::MARGEN, $base, $negro, $fuente, (string) $fila['izquierda']);
            $anchoDerecha = $this->anchoTexto((string) $fila['derecha'], $fuente, $tam);
            imagettftext($lienzo, $tam, 0, self::ANCHO - self::MARGEN - $anchoDerecha, $base, $negro, $fuente, (string) $fila['derecha']);

            return;
        }

        $texto = (string) $fila['texto'];
        $x = match ($fila['alineacion']) {
            'c' => (int) ((self::ANCHO - $this->anchoTexto($texto, $fuente, $tam)) / 2),
            'r' => self::ANCHO - self::MARGEN - $this->anchoTexto($texto, $fuente, $tam),
            default => self::MARGEN,
        };

        imagettftext($lienzo, $tam, 0, $x, $base, $negro, $fuente, $texto);
    }

    /**
     * @return array<string, mixed>
     */
    private function texto(string $texto, int $tam, string $alineacion, bool $negrita = false): array
    {
        return [
            'tipo' => 'texto',
            'texto' => $texto,
            'tam' => $tam,
            'alineacion' => $alineacion,
            'negrita' => $negrita,
            'alto' => (int) round($tam * 1.6),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function par(string $izquierda, string $derecha, int $tam, bool $negrita = false): array
    {
        return [
            'tipo' => 'par',
            'izquierda' => $izquierda,
            'derecha' => $derecha,
            'tam' => $tam,
            'negrita' => $negrita,
            'alto' => (int) round($tam * 1.6),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function separador(): array
    {
        return ['tipo' => 'separador', 'alto' => 16];
    }

    /**
     * Parte un texto en los renglones que caben a lo ancho del ticket, cortando por palabra. Una
     * descripción larga no puede salirse del papel ni imprimirse encima del importe.
     *
     * @return array<int, string>
     */
    private function partir(string $texto, int $tam, bool $negrita): array
    {
        $fuente = $this->fuente($negrita);
        $disponible = self::ANCHO - self::MARGEN * 2;
        $renglones = [];
        $actual = '';

        foreach (preg_split('/\s+/u', trim($texto)) ?: [] as $palabra) {
            $propuesta = $actual === '' ? $palabra : $actual.' '.$palabra;

            if ($actual !== '' && $this->anchoTexto($propuesta, $fuente, $tam) > $disponible) {
                $renglones[] = $actual;
                $actual = $palabra;

                continue;
            }

            $actual = $propuesta;
        }

        if ($actual !== '') {
            $renglones[] = $actual;
        }

        return $renglones === [] ? [''] : $renglones;
    }

    private function anchoTexto(string $texto, string $fuente, int $tam): int
    {
        $caja = imagettfbbox($tam, 0, $fuente, $texto);

        return $caja === false ? 0 : (int) abs($caja[2] - $caja[0]);
    }

    /**
     * Monoespaciada porque un ticket alinea importes en columna y una tipografía proporcional los
     * deja disparejos.
     *
     * Copiada a `resources/fonts/` y no leída desde `vendor/dompdf`: atar el ticket a la estructura
     * interna de una dependencia lo deja a merced del siguiente `composer update`.
     */
    private function fuente(bool $negrita): string
    {
        return resource_path('fonts/DejaVuSansMono'.($negrita ? '-Bold' : '').'.ttf');
    }

    private function dinero(float $monto): string
    {
        return '$'.number_format($monto, 2);
    }
}
