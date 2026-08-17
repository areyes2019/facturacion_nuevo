/**
 * Modo mostrador (ver 029-pwa-mostrador.md).
 *
 * El sistema decide al abrirse si muestra cuatro botones o el sistema entero, y lo decide por
 * **cómo se abrió la aplicación**: desde el icono instalado, o desde el navegador. Instalar la
 * aplicación en el celular del mostrador es un acto deliberado —alguien la instaló ahí para
 * vender—, mientras que el ancho de la pantalla es un accidente: una ventana angosta en la
 * computadora, o una tableta grande, caerían del lado equivocado sin que nadie lo haya pedido.
 *
 * **Consecuencia asumida**: instalar la aplicación en la computadora también la pondría en modo
 * mostrador. Se acepta a cambio de no mantener un ajuste ni adivinar por el tamaño; desinstalarla,
 * o abrirla desde el navegador, devuelve el sistema completo.
 */

/**
 * Se evalúa **una sola vez al arrancar** y el resultado se guarda: una ventana o se abrió desde el
 * icono o se abrió desde el navegador, y eso no cambia mientras esté abierta. Consultarlo en cada
 * repintado solo abriría la puerta a que media aplicación se dibujara de un modo y media del otro.
 */
const MODO = calcular()

function calcular(): boolean {
  // En desarrollo hace falta poder ver el modo mostrador sin instalar nada. En producción el
  // parámetro se ignora: si sirviera, cualquiera podría dejarse encerrado en los cuatro accesos
  // sin saber cómo salir.
  if (import.meta.env.DEV && new URLSearchParams(window.location.search).get('mostrador') === '1') {
    return true
  }

  return window.matchMedia('(display-mode: standalone)').matches
}

export function enModoMostrador(): boolean {
  return MODO
}

/**
 * Las únicas rutas alcanzables en modo mostrador.
 *
 * "No se llega por ningún medio" no se cumple escondiendo el menú: se cumple en el guard del
 * router. Una dirección vieja guardada en el navegador, un enlace pegado o un botón que quedó
 * apuntando a una pantalla de escritorio terminan en los cuatro accesos, en vez de mostrar media
 * aplicación que ahí no se puede usar.
 */
const RUTAS_PERMITIDAS = new Set([
  // Los cuatro accesos.
  'dashboard',
  'mostrador-venta',
  'mostrador-factura',
  'mostrador-cotizacion',
  'mostrador-escanear',
  // Las tres secciones de la barra del pie (ver 031-mostrador-consulta.md). Consultar sí se puede
  // desde el celular, con sus propias pantallas de tarjetas; editar sigue siendo trabajo de
  // computadora, y eso lo cierra cada pantalla, no el candado.
  'mostrador-cotizaciones',
  'mostrador-cotizacion-ver',
  'mostrador-facturas',
  'mostrador-factura-ver',
  'mostrador-catalogo',
  'mostrador-articulo-ver',
  // El destino del QR de la etiqueta, sin el cual el cuarto acceso no serviría.
  'pedidos-entregar',
  // La puerta de entrada.
  'login',
  'forgot-password',
  'reset-password',
])

/**
 * Rutas públicas que no pertenecen al sistema con sesión: las abre el cliente desde su propio
 * teléfono y no tienen nada que ver con el aparato del mostrador, así que quedan fuera del candado.
 */
const RUTAS_PUBLICAS = new Set(['autofactura'])

export function rutaBloqueadaEnMostrador(nombre: string): boolean {
  return enModoMostrador() && !RUTAS_PERMITIDAS.has(nombre) && !RUTAS_PUBLICAS.has(nombre)
}
