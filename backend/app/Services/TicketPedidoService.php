<?php

namespace App\Services;

use App\Enums\ClaveConfiguracion;
use App\Models\Emisor;
use App\Models\Pedido;
use GdImage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Dibuja el ticket de compra de un pedido de mostrador (ver 027-venta-mostrador-ticket.md).
 *
 * **Lo dibuja el servidor y no el navegador.** El mismo pedido se comparte desde la computadora del
 * mostrador y desde el celular del usuario; si cada aparato dibujara la imagen con sus propias
 * fuentes, al cliente le llegarían tickets de distinto aspecto según desde dónde se mandó.
 * Dibujarlo una vez y guardarlo también evita rehacerlo cada vez que se vuelve a compartir.
 *
 * Vive en el **disco privado**, fuera del docroot, por las dos razones de 020-imagenes-articulos.md:
 * `symlink` está desactivada en Hostinger (018) y `deploy/deploy-frontend.sh` borra del docroot todo
 * lo que no venga en el build.
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
     * Zona horaria del negocio (mono-usuario/mono-empresa): la hora impresa en el ticket es la del
     * mostrador, no la de UTC en que se guardó `created_at`. Mismo criterio que el listado de
     * cotizaciones en 008.
     */
    private const ZONA_HORARIA_NEGOCIO = 'America/Mexico_City';

    public function __construct(private readonly ConfiguracionService $configuracion) {}

    /**
     * Ruta del ticket dentro del disco privado, dibujándolo si aún no existe.
     */
    public function rutaDe(Pedido $pedido): string
    {
        if ($pedido->ticket_ruta !== null && Storage::disk('local')->exists($pedido->ticket_ruta)) {
            return $pedido->ticket_ruta;
        }

        return $this->generar($pedido);
    }

    public function contenido(Pedido $pedido): string
    {
        return (string) Storage::disk('local')->get($this->rutaDe($pedido));
    }

    /**
     * Tira el ticket guardado. Se llama cada vez que cambia algo de lo que el ticket muestra —las
     * líneas o los pagos—, para que nunca mienta sobre el saldo pendiente. Se vuelve a dibujar solo
     * la próxima vez que alguien lo pida.
     */
    public function invalidar(Pedido $pedido): void
    {
        if ($pedido->ticket_ruta !== null) {
            Storage::disk('local')->delete($pedido->ticket_ruta);
        }

        $pedido->ticket_ruta = null;
        $pedido->save();
    }

    /**
     * El mensaje que viaja junto a la imagen, con los huecos ya resueltos.
     *
     * Se resuelve en backend para que el frontend no tenga que conocer la lista de huecos, y un
     * hueco que no exista se deja tal cual: el texto es de captura libre y un `{}` mal escrito no
     * debe romper el envío.
     */
    public function mensajeCompartible(Pedido $pedido): string
    {
        $plantilla = $this->configuracion->obtener($pedido->user, ClaveConfiguracion::MensajeTicket);

        return strtr($plantilla, [
            '{nombre}' => (string) $pedido->cliente_nombre,
            '{folio}' => $pedido->numeroTicket(),
            '{total}' => $this->dinero((float) $pedido->total),
            '{pagado}' => $this->dinero($pedido->totalPagado()),
            '{saldo}' => $this->dinero($pedido->saldoPendiente()),
        ]);
    }

    /**
     * Dibuja el ticket y lo guarda. Dos pasadas sobre la misma lista de filas: la primera mide el
     * alto total, la segunda pinta. Sin medir antes no hay forma de crear el lienzo del tamaño
     * correcto, y un lienzo fijo dejaría tickets largos cortados o tickets cortos con medio metro
     * de blanco abajo.
     */
    private function generar(Pedido $pedido): string
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

        $ruta = Pedido::DIRECTORIO_TICKETS."/pedido-{$pedido->id}.jpg";
        Storage::disk('local')->put($ruta, $binario);

        $pedido->ticket_ruta = $ruta;
        $pedido->save();

        return $ruta;
    }

    /**
     * La lista de filas del ticket, en el orden en que se imprimen.
     *
     * **Sin código QR**: el QR va únicamente en la etiqueta, que es lo que se pega al trabajo. En el
     * ticket no serviría de nada, porque el cliente no cierra su propio pedido.
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

            $filas[] = $this->par(
                $linea->cantidad.' x '.$this->dinero((float) $linea->precio_unitario),
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
        $filas[] = $this->par('TOTAL', $this->dinero((float) $pedido->total), self::TAM_NORMAL, negrita: true);

        $filas[] = $this->separador();
        $filas[] = $this->par('Pagado', $this->dinero($pedido->totalPagado()), self::TAM_NORMAL);
        $filas[] = $this->par('Saldo pendiente', $this->dinero($pedido->saldoPendiente()), self::TAM_NORMAL, negrita: true);

        $filas[] = $this->separador();
        $filas[] = $this->texto('¡Gracias por tu compra!', self::TAM_CHICO, 'c');

        return $filas;
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
