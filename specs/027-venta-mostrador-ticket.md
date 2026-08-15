# Spec: Venta de mostrador (pedido, ticket, etiqueta con QR y autofactura)

## Historia de usuario

Como usuario único del sistema, quiero atender al cliente que entra al local y compra directo —sin
cotización de por medio—, cobrarle un anticipo o el total, entregarle un ticket de compra estilo
punto de venta por WhatsApp, pegarle al trabajo una etiqueta con un código QR que al escanearlo dé
por entregado el pedido, y mandarle un enlace para que él mismo se haga su factura.

El flujo completo, tal como lo describió el usuario:

1. El cliente paga el anticipo.
2. Se levanta un pedido: nombre, teléfono y correo.
3. Se seleccionan los artículos y se agregan a la lista.
4. Se registra el anticipo o el pago completo, según sea el caso.
5. Se genera un `.jpg` estilo ticket y con un botón compartir —como el de las imágenes de
   artículos— se le manda al cliente. Al mismo tiempo se genera una etiqueta para imprimir con
   nombre, no. de ticket, teléfono y un código QR.
6. Junto con la imagen viaja un mensaje explicando los siguientes pasos.
7. Cuando el sello se entrega, se escanea con el celular el QR de la etiqueta y el pedido queda
   cobrado y entregado.
8. Se genera un enlace para que el cliente se autofacture y se le manda por WhatsApp con un botón
   compartir.

## Objetivo / Alcance

Un módulo nuevo, **Pedidos**, con su propio documento, su propio folio y su propio listado
`/pedidos`. Incluye: captura del cliente de mostrador dentro del propio pedido (sin RFC), líneas de
artículo del catálogo **y líneas libres a mano**, registro de pagos contra Tesorería, ticket JPG
dibujado en el servidor, etiqueta imprimible de 5 × 2.5 cm con QR, cierre automático del pedido al
escanear ese QR, y un **portal público de autofacturación** que timbra la factura del pedido con
Facturapi.

Se apoya en lo que ya existe y **no lo modifica salvo donde se diga explícitamente**: el cálculo de
totales de [007](007-facturacion.md) (`FacturaTotalesCalculator`), el componente de líneas
`DocumentoLineas` de [008](008-cotizaciones.md), la escritura de movimientos de
[010](010-tesoreria.md) (`TesoreriaService`), el descuento de existencias de
[017](017-inventario.md) (`InventarioService`), el patrón del botón "Compartir" de
[020](020-imagenes-articulos.md), la generación de QR de [019](019-formato-pdf-documentos.md)
(`chillerlan/php-qrcode`), y el timbrado de [007](007-facturacion.md) (`FacturapiService`).

### Por qué un documento nuevo y no una cotización

La Cotización de [008](008-cotizaciones.md) parece cubrir esto —tiene líneas, pagos con anticipo,
estados y envío— pero choca en tres puntos que no son de forma:

- **Exige un `Cliente` fiscal.** `cotizaciones.cliente_id` es obligatorio y `Cliente` exige `rfc`,
  `razon_social`, `regimen_fiscal` y `codigo_postal_fiscal` ([004](004-gestion-clientes.md)). El
  cliente de mostrador entrega nombre, teléfono y correo, y muchas veces nunca pedirá factura.
  Forzarlo al catálogo fiscal lo llenaría de registros con RFC genérico que después estorban en el
  buscador de facturas.
- **El dinero llega en el orden inverso.** Una cotización nace sin pago y espera aprobación; aquí el
  cliente ya pagó antes de que exista el documento. Los estados `borrador → enviada` no describen
  nada de esta venta.
- **La entrega se cierra distinto.** En cotización, `producto_entregado` se marca a mano desde el
  detalle. Aquí se cierra escaneando una etiqueta, cobrando el saldo de paso.

Meter todo eso en `Cotizacion` significaría hacer `cliente_id` nullable, agregar dos estados que no
aplican al flujo viejo y ramificar cada pantalla según el tipo. Sale más caro que un documento
propio que reusa los mismos servicios.

## Backend (Laravel)

### Modelo `Pedido`

`$table = 'pedidos'` explícito, misma lección ya pagada en 005, 008, 012 y 017.

- Pertenece a un `User` (`user_id`).
- `folio`: entero autoincremental **por usuario**, independiente de `Factura` y de `Cotizacion`. Se
  calcula igual que en 008 (`max('folio') + 1` dentro de la transacción de creación). **Este folio
  es el "No. de ticket"** que se imprime en la etiqueta y en el ticket.
- **Datos del cliente, dentro del propio pedido** (no hay FK a `Cliente`):
  - `cliente_nombre`: string, **obligatorio**.
  - `cliente_telefono`: string, **obligatorio**.
  - `cliente_correo`: string, **opcional**.

  El teléfono es obligatorio para identificar al cliente cuando vuelve y para imprimirlo en la
  etiqueta. **No se usa para enviar nada**: el envío lo resuelve el botón compartir del sistema
  operativo, donde el usuario elige el contacto. Por eso aquí no hay integración con Twilio, a
  diferencia de 008.
- **Descuento global y totales**: `descuento_global_tipo`, `descuento_global_valor`, `subtotal`,
  `total_descuento`, `total_iva_16`, `total_iva_0`, `total_exento`, `total`. Mismo esquema y mismo
  `FacturaTotalesCalculator` de 007, recalculados siempre en backend, `422` si el `total` enviado no
  coincide con el recalculado. **No** aplica el descuento permanente de cliente de
  [015](015-descuento-permanente-cliente.md): ese vive en `Cliente` y aquí no hay `Cliente`.
- `estado`: enum `EstadoPedido` (abajo).
- `entregado_en`: `timestamp` nullable. Momento exacto en que el escaneo cerró el pedido; es lo que
  hace posible la ventana de "Deshacer".
- `ticket_ruta`: string nullable, **fuera de `#[Fillable]`**. Ruta del JPG dentro del disco privado;
  solo la escribe `TicketPedidoService`. Mismo criterio que `articulos.imagen_ruta` en
  [020](020-imagenes-articulos.md) y `emisor.logo_ruta` en [019](019-formato-pdf-documentos.md).
- `autofactura_token`: string(64) nullable, único, **fuera de `#[Fillable]`**. Ver "Portal de
  autofacturación".
- `autofactura_error`: text nullable, **fuera de `#[Fillable]`**. Último motivo por el que falló un
  intento de timbrado desde el portal público.
- `factura_id`: FK nullable a `Factura`. Se llena cuando el cliente se autofactura. Un pedido con
  `factura_id` no nulo ya no puede generar otra factura.
- Sin soft delete: el `DELETE` es físico (se lleva sus líneas). Se permite solo en estado
  `pendiente` y **sin ningún pago registrado** — mismo criterio que 008: los pagos tienen un
  movimiento de Tesorería colgado y hay que borrarlos con el endpoint de pagos, que es quien sabe
  revertir el movimiento y recalcular el saldo de la cuenta.

Campos derivados que expone el recurso (no columnas): `pagado` (suma de `pedido_pagos.monto`) y
`saldo` (`total - pagado`).

### Modelo `PedidoLinea`

Mismo esquema que `CotizacionLinea`: `pedido_id`, `articulo_id` (**nullable**), `cantidad` (entero,
mínimo 1), `descripcion`, `modelo`, `precio_unitario` (mayor a 0), `descuento_tipo`,
`descuento_valor`, `tasa_iva`, `importe`, `iva_importe`.

**`articulo_id` nullable es la línea libre.** El usuario puede agregar una línea escribiendo
descripción, cantidad y precio a mano, sin que el producto exista en el catálogo. Una línea libre:

- **No mueve inventario.** `InventarioService::cantidadesPorArticulo` ya descarta las líneas sin
  `articulo_id` —el texto libre existe desde 012— así que esto funciona sin tocar el servicio.
- **No se da de alta en el catálogo.** Es una venta suelta, no un producto nuevo.
- **Sí se puede facturar**, con claves SAT genéricas (ver "Portal de autofacturación").

Los precios de las líneas con artículo se precargan del catálogo con el **precio con IVA redondeado
a peso entero** de [024](024-precios-sin-centavos.md), y cantidad y precio quedan editables en la
línea, igual que en 008.

### Modelo `PedidoPago`

`$table = 'pedido_pagos'`. Campos: `pedido_id`, `fecha_pago`, `monto`, `cuenta_id`, `automatico`.

Sigue la decisión de 008: **`cuenta_id`, no `forma_pago`**. Un pedido no es un CFDI, así que la
forma de pago del catálogo del SAT no se timbra nunca desde aquí; lo que importa es a qué cuenta de
Tesorería entra el dinero. La forma de pago fiscal se deriva después, solo si el cliente se
autofactura (ver más abajo).

- Se pueden registrar **varios pagos** en el mismo pedido (anticipo hoy, saldo al entregar), sin
  porcentaje mínimo: el usuario captura el monto que recibió.
- Cada pago genera su `Movimiento` de ingreso vía `TesoreriaService`, con concepto no editable
  `Pago de Pedido PED-00042` (mismo formato de `CotizacionPago::conceptoMovimiento`).
- `automatico`: booleano, `false` por omisión. Lo pone en `true` **solo** el cobro que dispara el
  escaneo del QR. Es lo que permite que "Deshacer" sepa qué pago borrar sin adivinar por monto o
  por fecha.

### Estados

`EstadoPedido`: `pendiente` → `anticipo` → `pagado` → `entregado`.

- `pendiente`: recién creado, sin ningún pago.
- `anticipo`: hay pagos pero la suma no alcanza el `total`.
- `pagado`: la suma de pagos alcanza o supera el `total`.
- `entregado`: cerrado por el escaneo del QR.

Los tres primeros **se derivan solos** de la suma de pagos, cada vez que se agrega o se borra un
pago; no son un campo que el usuario mueva. Solo `entregado` es un estado que se escribe
explícitamente.

`esEditable()` devuelve `true` en `pendiente` y `anticipo`. En `pagado` y `entregado` el pedido
queda congelado: el ticket ya salió hacia el cliente y las líneas ya no deben cambiar debajo de él.

### Inventario

Al **crear** el pedido —no al entregarlo— se descuentan existencias con
`InventarioService::salidaPorDocumento`, con un motivo nuevo
`MotivoMovimientoInventario::VentaPedido` (`venta_pedido`). Es la misma regla que factura y
cotización en [017](017-inventario.md): la salida nunca bloquea la operación; si no alcanza, se
genera faltante.

Al **editar** un pedido editable se revierte el movimiento anterior y se aplica el nuevo, igual que
hace hoy `CotizacionController` con sus líneas. Al **borrar** un pedido (solo posible en
`pendiente`) se devuelven las existencias.

**La factura nacida de una autofactura no descuenta nada**, porque el pedido ya descontó. Se extiende
`Factura::mueveInventario()` para que devuelva `false` también cuando existe un `Pedido` que la
apunta, exactamente como ya hace con `Cotizacion`.

### El ticket `.jpg`

**Lo dibuja el servidor, no el navegador.** El motivo es concreto: el mismo pedido se comparte desde
la computadora del mostrador y desde el celular del usuario, y si cada aparato dibujara la imagen con
sus propias fuentes, al cliente le llegarían tickets de distinto aspecto según desde dónde se mandó.
Dibujarlo una vez y guardarlo también evita rehacerlo cada vez que se vuelve a compartir.

`TicketPedidoService`, con **GD** (`imagecreatetruecolor`, `imagettftext`, `imagejpeg`), ya
confirmada disponible en local y verificada como requisito en [020](020-imagenes-articulos.md).

- **Ancho fijo 576 px**, el ancho útil de una impresora térmica de 80 mm a 203 dpi. Alto variable,
  calculado a partir del número de líneas.
- **Fuente**: `DejaVuSansMono.ttf` y `DejaVuSansMono-Bold.ttf`, **copiadas a
  `backend/resources/fonts/`**. Vienen con dompdf, pero leerlas desde `vendor/` ataría el ticket a
  la estructura interna de una dependencia que `composer update` puede reacomodar. Monoespaciada
  porque un ticket alinea importes en columna y una tipografía proporcional los deja disparejos.
- **Contenido**, en este orden:
  1. Logo del emisor centrado, reducido a 240 px de ancho máximo con `ProcesadorImagen` (se omite si
     no hay logo).
  2. Nombre, teléfono y domicilio del emisor.
  3. `TICKET No. 00042` y la fecha y hora de la venta.
  4. Nombre del cliente.
  5. Las líneas: cantidad, descripción, precio unitario e importe.
  6. Subtotal, descuento (si lo hay), IVA y **Total**.
  7. **Pagado** y **Saldo pendiente**.

  **Sin código QR**: el QR va únicamente en la etiqueta, que es lo que se pega al trabajo. En el
  ticket no serviría de nada — el cliente no cierra su propio pedido.
- **Salida JPEG calidad 85**, guardado en el disco privado (`Storage::disk('local')`) bajo
  `Pedido::DIRECTORIO_TICKETS` (`pedidos/tickets`), y servido por una ruta de Laravel que primero
  valida la sesión. Fuera del docroot por las dos razones de [020](020-imagenes-articulos.md):
  `symlink` está desactivada en Hostinger y `deploy/deploy-frontend.sh` borra todo lo que no venga
  en el build.
- **Se regenera cuando cambia lo que muestra**: al guardar el pedido y al agregar o borrar un pago,
  se borra el archivo anterior y se marca `ticket_ruta = null`; se vuelve a dibujar la próxima vez
  que se pida. Así el ticket nunca miente sobre el saldo.

Se comparte tal cual en JPEG, sin la conversión desde WEBP que hace la ficha de artículo — aquí el
archivo ya nace en el formato que WhatsApp trata como foto y no como calcomanía.

### El mensaje que acompaña al ticket

Vive en el almacén clave→valor de Configuración ([014](014-costo-elaboracion-goma.md)), como clave
nueva `ClaveConfiguracion::MensajeTicket` (`mensaje_ticket`), con reglas `['string', 'max:2000']`.
Es la primera clave de texto del almacén; las tres existentes son numéricas, así que `reglas()`
gana un `match` que ya no devuelve lo mismo para todos los casos.

Su `valorPorDefecto()` es el texto que dictó el usuario:

```
¿Qué sigue ahora?
😊 Te explico los siguientes pasos:

1️⃣ Primero trabajaremos en el diseño de tu sello. ✍️ Te enviaremos una propuesta para que la
revises y nos confirmes si estás de acuerdo. 👀

2️⃣ Una vez aprobado, comenzamos la producción. El tiempo estimado de entrega es de 24 a 48 horas
hábiles. ⏳

Por ejemplo, si apruebas el diseño un viernes, tu sello estará listo para el lunes. 📅

Cuando esté terminado, podrás elegir cómo recibirlo:
📍 Recogerlo personalmente o
🚚 Recibirlo por paquetería, directo a tu domicilio.

¿Te parece bien? Quedo atento(a) a tu confirmación. 😉
```

**Admite huecos que el sistema rellena solo** al momento de compartir: `{nombre}`, `{folio}`,
`{total}`, `{pagado}` y `{saldo}`. El usuario escribe la plantilla una vez y cada cliente recibe su
mensaje personalizado sin teclear nada. Un hueco que no exista se deja tal cual, sin reventar: el
texto es de captura libre y un `{}` mal escrito no debe romper el envío. El reemplazo se hace en
backend y el recurso del pedido expone el mensaje **ya resuelto**, para que el frontend no tenga que
conocer la lista de huecos.

Debajo del campo, en Configuración, se muestra la lista de huecos disponibles.

### La etiqueta con QR

Documento aparte del ticket, pensado para etiqueta adhesiva. Se imprime **desde el navegador**, no
se genera archivo: una vista dedicada con `@page { size: 50mm 25mm; margin: 0 }` y `@media print`,
que llama a `window.print()` al montarse.

Contenido, y nada más: **nombre del cliente, `No. 00042`, teléfono y el QR**. Sin precios, sin
artículos, sin totales — la etiqueta va pegada al trabajo y pasa por manos que no tienen por qué ver
lo que se cobró.

El QR se dibuja en el servidor con `chillerlan/php-qrcode`, igual que el del timbre fiscal de
[019](019-formato-pdf-documentos.md), y viaja en el recurso del pedido como data URI (`qr_imagen`),
solo en el `show`. Su contenido es la **URL absoluta** de la pantalla de entrega:

```
{APP_URL}/pedidos/{id}/entregar
```

Una URL absoluta y no un dato suelto, por dos razones: la app de cámara del celular la abre sola sin
que nadie copie ni pegue nada, y **la futura PWA con Capacitor podrá leer ese mismo QR con su propio
lector** y actuar sobre el `id` que trae la ruta, sin reimprimir una sola etiqueta. Esa PWA está
fuera del alcance de esta spec, pero el formato del QR se elige hoy para no cerrarle la puerta.

### El escaneo: cobrar y entregar de un golpe

Se escanea con la **app de cámara del propio celular**, que abre la URL en el navegador. No se
construye un lector de QR dentro del sistema.

La pantalla `/pedidos/:id/entregar` **requiere sesión**. Sin ella manda al login y regresa sola al
pedido al autenticarse. Es una acción que mueve dinero: nadie que encuentre la etiqueta tirada en la
calle debe poder cerrarla.

Al abrirse, y **sin pedir confirmación**, dispara `POST /api/v1/pedidos/{pedido}/entregar`, que en
una sola transacción:

1. Bloquea la fila del pedido con `lockForUpdate()`.
2. Si el pedido ya está `entregado`, **no toca nada** y responde "este pedido ya está entregado",
   con la fecha en que se entregó. Es el candado contra el doble escaneo: dos escaneos seguidos —o
   dos personas escaneando a la vez— no pueden cobrar el saldo dos veces ni meter dinero de más a la
   caja.
3. Si queda saldo, registra un `PedidoPago` por el saldo exacto, con `automatico = true`, y su
   movimiento de ingreso vía `TesoreriaService`.
4. Marca `estado = entregado` y `entregado_en = now()`.

**Con qué cuenta se cobra ese saldo.** El escaneo no puede preguntar nada, así que la cuenta se
elige por regla fija: **la cuenta de tipo `efectivo` más antigua del usuario** (menor `id`), que es
la caja del mostrador. Es lo más común al entregar en persona; si el cliente pagó por transferencia,
el pago se corrige después desde el detalle del pedido, borrándolo y recapturándolo con la cuenta
correcta. Si el usuario **no tiene ninguna cuenta de efectivo**, el escaneo marca entregado sin
cobrar y avisa en pantalla que hay que registrar el saldo a mano — no se inventa una cuenta ni se
bloquea la entrega del trabajo.

**El "Deshacer".** La pantalla muestra el resultado con un botón "Deshacer" durante **10 segundos**,
por si se escaneó la etiqueta equivocada. Llama a `POST /api/v1/pedidos/{pedido}/deshacer-entrega`,
que revierte **las dos cosas**:

- Borra el `PedidoPago` con `automatico = true` de ese pedido **y su movimiento de Tesorería**, con
  el mismo camino que ya usa el borrado de pagos de cotización: quien revierte el movimiento y
  recalcula el saldo de la cuenta es `TesoreriaService`. Si solo se regresara el estado, quedarían
  doscientos pesos fantasma en la caja.
- Deja `entregado_en = null` y recalcula el estado desde los pagos que quedan.

El backend lo acepta mientras el pedido esté `entregado` y `entregado_en` sea de hace **menos de 5
minutos**. La ventana del backend es más ancha que los 10 segundos del botón a propósito: el botón
mide impaciencia del usuario, el límite del servidor evita que un "Deshacer" disparado desde una
pestaña olvidada revierta mañana un cobro legítimo.

### Portal de autofacturación

Es funcionalidad enteramente nueva: el sistema no tenía nada parecido.

**Cuándo existe el enlace.** El `autofactura_token` se genera —`Str::random(64)`— en el momento en
que el pedido alcanza el estado `pagado`. Antes de eso no hay enlace: no se factura lo que no se ha
cobrado por completo.

**Por qué el token y no el `id`.** El enlace es público, sin contraseña, porque el cliente no tiene
cuenta en el sistema. Si fuera `/autofactura/1043`, cualquiera podría cambiar el número a mano y
meterse a facturar el pedido de otro, o ir probando números hasta encontrar pedidos sin facturar.
Con 64 caracteres al azar no hay nada que adivinar. Es el mismo criterio del PDF público firmado que
008 usa para que Twilio descargue la cotización sin sesión.

**Vigencia.** El enlace deja de funcionar el **último día del mes de la venta**, a las 23:59:59. Un
CFDI se emite en el periodo de la operación; pasado el mes, timbrar esa venta ya no procede y es
mejor que el cliente se tope con un aviso claro que con un timbrado rechazado por el SAT. Vencido,
la página explica que debe contactar al negocio.

**Un pedido, una factura.** Con `factura_id` no nulo el enlace deja de funcionar y el detalle del
pedido muestra el folio de la factura.

**Qué captura el cliente** (`GET /api/v1/autofactura/{token}` devuelve folio, fecha y total para que
sepa qué está facturando):

- RFC, razón social y régimen fiscal — reusando `RegimenFiscalSelect`.
- Código postal fiscal — reusando `CodigoPostalCombobox`.
- Uso de CFDI — reusando `UsoCfdiCombobox`.
- Correo, precargado con `cliente_correo` del pedido si lo hay.

**Qué pasa al enviar** (`POST /api/v1/autofactura/{token}`), en `AutofacturaService`:

1. Se crea el `Cliente` fiscal con esos datos (o se reusa el existente si el RFC ya está dado de
   alta para ese usuario). Aquí sí entra al catálogo: ya trae RFC y régimen, que es justo lo que le
   faltaba al de mostrador.
2. Se crea la `Factura` con sus `FacturaLinea`, copiadas del pedido con los mismos importes.
   - `metodo_pago`: **PUE** siempre. El pedido está totalmente pagado antes de que exista el enlace.
   - `forma_pago`: se deriva del tipo de la cuenta que recibió el **último** pago —`efectivo` → `01`
     (efectivo), `banco` → `03` (transferencia), `digital` → `03`, `otro` → `99` (por definir). El
     cliente no tiene por qué saber a qué cuenta entró su dinero, y el usuario ya lo capturó al
     registrar el pago.
   - **Líneas libres**: se timbran con `clave_prod_serv` `01010101` ("no existe en el catálogo") y
     `clave_unidad` `H87` (pieza). `FacturapiService::construirPayloadFactura` toma hoy esas dos
     claves de `linea->articulo`, que en una línea libre es `null`; sin este respaldo, Facturapi
     rechazaría el timbrado.
3. Se timbra con `FacturapiService::timbrarFactura`.
4. Se escribe `pedidos.factura_id` **dentro de la transacción, antes de timbrar** — mismo orden que
   017 impuso al vínculo factura → cotización, para que la factura no se vea como venta directa y
   descuente existencias que el pedido ya descontó.
5. Se le manda la factura por correo con el mecanismo que ya existe
   (`EnvioDocumentoService`/`FacturaController::enviarCorreo`).

**Cuando el timbrado falla** —RFC inexistente, código postal que no coincide con el que el SAT tiene
registrado, servicio caído—:

- **El cliente ve el motivo en español** y puede corregir y reintentar ahí mismo. Los códigos de
  Facturapi se traducen a frases entendibles; lo que no esté mapeado cae en un mensaje genérico
  seguro. Sin esto, el cliente lee "error 500", cierra la pestaña y se queda sin factura.
- **El pedido queda marcado para el usuario**: `autofactura_error` guarda el motivo y el listado de
  `/pedidos` muestra una señal en esa fila. Es la única forma de enterarse de que un cliente intentó
  facturar y no pudo — ese cliente ya se fue y no va a insistir.

Un intento fallido **no** consume el enlace ni deja factura a medias: todo corre dentro de una
transacción que se revierte, y el token sigue vivo hasta su vencimiento.

### Endpoints

Bajo `auth:sanctum`, scopeados al usuario autenticado:

- `Route::apiResource('pedidos', PedidoController::class)` — listado con filtros por columna
  combinables (folio, nombre, teléfono, estado, rango de fechas), en el mismo estilo de
  [025](025-filtros-columna-listado-articulos.md).
- `POST pedidos/{pedido}/pagos` — registra un pago.
- `DELETE pedidos/{pedido}/pagos/{pago}` — lo borra y revierte su movimiento.
- `GET pedidos/{pedido}/ticket` — devuelve el JPG (lo dibuja si aún no existe).
- `POST pedidos/{pedido}/entregar` — cobro automático del saldo + entrega. Idempotente.
- `POST pedidos/{pedido}/deshacer-entrega` — revierte lo anterior dentro de la ventana de 5 minutos.

**Fuera** de `auth:sanctum`, junto al `cotizaciones/{cotizacion}/pdf-publico` que ya vive ahí:

- `GET autofactura/{token}` — folio, fecha y total del pedido, o el motivo por el que el enlace ya
  no sirve (vencido, ya facturado, inexistente).
- `POST autofactura/{token}` — datos fiscales; crea cliente, factura y timbra.

Ambas con throttling explícito (`throttle:20,1`), porque son las únicas rutas del sistema que
cualquiera en internet puede llamar.

### Validaciones (Form Requests)

- **Alta/edición**: `cliente_nombre` requerido, string, máx 150. `cliente_telefono` requerido,
  string, máx 30. `cliente_correo` opcional, email. Al menos una línea. Por línea: `articulo_id`
  nullable y, si viene, debe pertenecer al usuario; `descripcion` requerida siempre —también en la
  línea libre, que es lo único que la identifica—; `cantidad` entero ≥ 1; `precio_unitario` > 0;
  `tasa_iva` del enum. `total` enviado debe coincidir con el recalculado (`422`).
- **Edición bloqueada** con `422` si el estado no es editable.
- **Pago**: `monto` > 0 y no mayor al saldo pendiente; `cuenta_id` propia del usuario; `fecha_pago`
  válida.
- **Autofactura**: `rfc` válido (`phpcfdi/rfc`, ya instalado), `razon_social`, `regimen_fiscal` del
  catálogo, `codigo_postal_fiscal` de 5 dígitos, `uso_cfdi` del catálogo, `correo` email válido.

### Tests

Feature, con Facturapi mockeado (Mockery), en el estilo del resto del proyecto:

1. Alta de pedido con líneas de catálogo y líneas libres; totales recalculados en backend.
2. Alta con `total` manipulado desde el frontend → `422`.
3. El folio es consecutivo por usuario e independiente de facturas y cotizaciones.
4. Crear un pedido descuenta existencias solo de las líneas con `articulo_id`.
5. Registrar un anticipo deja el pedido en `anticipo`; completar el saldo lo deja en `pagado` y
   genera el `autofactura_token`.
6. Cada pago genera su movimiento de ingreso y mueve el saldo de la cuenta.
7. Editar un pedido `pagado` → `422`.
8. Borrar un pedido con pagos → `422`.
9. `POST entregar` con saldo pendiente cobra el saldo a la cuenta de efectivo más antigua, marca
   `entregado` y deja el pago con `automatico = true`.
10. `POST entregar` dos veces seguidas cobra **una sola vez** (candado).
11. `POST entregar` sin cuentas de efectivo marca entregado sin cobrar y lo reporta.
12. `deshacer-entrega` borra el pago automático, revierte el movimiento, restaura el saldo de la
    cuenta y regresa el estado.
13. `deshacer-entrega` pasados 5 minutos → `422`, sin tocar nada.
14. El ticket se genera, es un JPEG válido, y se invalida al agregar o borrar un pago.
15. El mensaje del ticket resuelve los huecos y deja intactos los que no existen.
16. `GET autofactura/{token}` inexistente, vencido o ya facturado responde con su motivo.
17. `POST autofactura/{token}` crea el cliente fiscal, timbra, escribe `factura_id` y **no**
    descuenta inventario por segunda vez.
18. Timbrado fallido: la transacción se revierte, `autofactura_error` queda escrito, el token sigue
    vivo y no hay factura huérfana.
19. Un pedido ya facturado rechaza un segundo intento.
20. Todas las rutas de `/pedidos` responden `401` sin sesión; las de autofactura responden sin ella.

## Frontend (Vue 3)

### Pantallas

- **`/pedidos`** — listado con folio, cliente, teléfono, fecha, total, pagado, saldo y estado, con
  filtros por columna combinables. Una **señal en la fila** cuando `autofactura_error` no está
  vacío.
- **`/pedidos/crear`** y **`/pedidos/:id/editar`** — datos del cliente arriba (nombre, teléfono,
  correo) y `DocumentoLineas` abajo, reusando `ArticuloBuscador`. El componente ya soporta líneas de
  texto libre desde 012; aquí se habilita esa opción con un botón "Agregar línea libre".
  - Al capturar un teléfono que ya existe en otro pedido, se ofrece **rellenar nombre y correo** con
    los de la venta anterior. Es una sugerencia que se acepta o se ignora, no un autocompletado que
    pise lo que el usuario esté escribiendo.
- **`/pedidos/:id`** — detalle: datos del cliente, líneas, totales, historial de pagos con su cuenta,
  vista previa del ticket, y los botones **"Compartir ticket"**, **"Imprimir etiqueta"**, **"Agregar
  pago"** y —cuando está pagado— **"Compartir enlace de autofactura"**. Si ya se facturó, el folio de
  la factura con enlace a `/facturas/:id`.
- **`/pedidos/:id/etiqueta`** — vista de impresión de 5 × 2.5 cm, sin layout de la aplicación, que
  llama a `window.print()` al montarse.
- **`/pedidos/:id/entregar`** — destino del QR. Dispara la entrega al montarse y muestra el
  resultado: qué se cobró, a qué cuenta, y el botón "Deshacer" con su cuenta regresiva de 10
  segundos. Si el pedido ya estaba entregado, lo dice y no ofrece deshacer.
- **`/autofactura/:token`** — **pública**, fuera del guard de sesión y sin el layout de la
  aplicación: la ve un cliente, no el usuario. Muestra folio, fecha y total, el formulario fiscal, y
  al terminar el acuse con el folio de la factura timbrada.

### El botón "Compartir"

Mismo patrón que la ficha de artículo de [020](020-imagenes-articulos.md), decidiendo en tiempo real
con `navigator.canShare({ files })` y no adivinando por el tamaño de la pantalla:

- **En celular**: abre el menú de compartir del sistema con **la imagen del ticket y el mensaje**.
  Es el único camino por el que una imagen puede salir hacia WhatsApp desde una página web.
- **En escritorio**: **descarga el `.jpg` y copia el mensaje al portapapeles**, avisando "Ticket
  descargado y mensaje copiado". El usuario arrastra la imagen a WhatsApp Desktop y pega el texto.
  Aquí sí se descarga la imagen, a diferencia de la ficha de artículo, porque el ticket no existe en
  ningún otro lado y sin el archivo el botón no sirve de nada en la computadora del mostrador.

El botón de **enlace de autofactura** es distinto porque comparte texto, no imagen: en Windows abre
**WhatsApp Desktop** con el mensaje y el enlace ya escritos (`https://wa.me/?text=…`, que el sistema
enruta a la aplicación instalada), y deja al usuario elegir el contacto. Si no hay WhatsApp, el mismo
enlace abre WhatsApp Web. En celular usa `navigator.share` con el texto.

### Configuración

Sección nueva **"Mensaje del ticket"**, hermana de las que ya existen
([014](014-costo-elaboracion-goma.md), [019](019-formato-pdf-documentos.md),
[026](026-datos-bancarios-cotizacion.md)): un `textarea` con el mensaje y, debajo, la lista de huecos
disponibles con un ejemplo de cómo se ven resueltos.

### Navegación

Se agrega **Pedidos** al grupo **Ventas** de [013](013-navegacion-principal.md), en primer lugar
—Pedidos · Facturas · Cotizaciones · Clientes—, siguiendo el flujo comercial: es la venta que entra
por la puerta. Ícono `TicketIcon` de Heroicons. Se declara en `navegacion.ts`, como manda 013.

Las rutas `/pedidos/:id/etiqueta`, `/pedidos/:id/entregar` y `/autofactura/:token` **no** aparecen en
el menú: a las dos primeras se llega desde el detalle o desde el QR, y la tercera es del cliente.

## Fuera de alcance

- **La PWA con Capacitor** y su lector de QR integrado. El formato del QR se elige hoy pensando en
  ella, pero la aplicación es otra historia.
- **Envío automático por WhatsApp vía Twilio.** Todos los envíos los dispara el usuario con un botón
  y pasan por el menú de compartir del sistema. Nada se manda solo.
- **Convertir un pedido en cotización**, o al revés.
- **Cancelar la factura generada por autofactura** desde el pedido: se cancela desde
  `/facturas/:id`, con el flujo de 007 que ya existe.
- **Refacturar**: un pedido genera una sola factura.
- **Devoluciones o reembolsos** del pedido.
- **Impresión térmica directa** del ticket. El ticket se comparte como imagen; imprimirlo en una
  térmica requiere driver o app intermedia y no se pidió.
- **Complemento de pago** para pedidos: como el timbrado ocurre con el pedido ya pagado (PUE), no
  hay saldo fiscal pendiente que complementar.

## Estado de implementación

**Implementada el 2026-08-14.** Backend, frontend y pruebas completos; `php artisan test` en verde
(538 pruebas), `pint` sin cambios, `npm run build`, `npm run lint` y `vitest` en verde.

### Decisiones tomadas durante la implementación

- **`MotivoMovimientoInventario::CorreccionPedido`**, un motivo automático que la spec no había
  previsto. Editar o borrar un pedido tiene que devolver al inventario lo que descontó el alta, y
  reusar `VentaPedido` para la entrada dejaría un historial donde el mismo motivo significa cosas
  opuestas según el tipo de movimiento. Sin él, un pedido editado tres veces habría descontado tres
  veces la misma mercancía.
- **`ClaveConfiguracion::reglas()` ahora declara también la obligatoriedad.** El almacén de 014 solo
  tenía claves numéricas y `ConfiguracionController` anteponía `required` a todas. El mensaje del
  ticket es la primera clave de texto y **sí puede quedar vacío** —el usuario puede querer mandar
  solo la imagen—, así que la regla se mudó al enum, donde depende de la clave y no del endpoint.
  Lleva `present` + `nullable`, porque Laravel convierte la cadena vacía en `null` antes de validar.
- **`regimenes-fiscales` y `usos-cfdi` salieron del grupo autenticado.** El portal público necesita
  esas dos listas para que el cliente llene sus datos fiscales. No revelan nada del negocio: son las
  mismas listas que el SAT publica, servidas desde `phpcfdi/sat-catalogos` sin tocar la base de
  datos. Los otros siete catálogos siguen detrás del login. Van con `throttle:60,1`, más holgado que
  el `20,1` de autofactura, porque el buscador de uso de CFDI hace una petición por búsqueda
  mientras el cliente escribe.
- **El portal público pide la cookie CSRF antes de enviar** (`ensureCsrfCookie()`). El grupo `api`
  lleva `EnsureFrontendRequestsAreStateful`, y quien abre el enlace llega sin cookie.
- **`DocumentoLineas` admite `articulo_id: null`** y gana la bandera `permiteLineaLibre`. Los
  payloads de factura, cotización y orden de compra se ampliaron a `number | null` por el tipo
  compartido; en esos tres documentos el buscador siempre pone un id y el backend lo exige, así que
  el comportamiento no cambia.
- **La lógica de compartir se extrajo a `src/lib/compartir.ts`**, que ahora sostiene los tres casos:
  imagen + texto por el menú del sistema, descarga + portapapeles en escritorio, y texto suelto por
  `wa.me`. La ficha de artículo de 020 conserva su propia versión, que hace algo distinto en
  escritorio (no descarga) y convierte WEBP a JPEG.
- **El guard del router recuerda el destino** (`?redirect=`) y el login regresa ahí, aceptando solo
  rutas internas. Sin esto, escanear el QR sin sesión abierta dejaba al usuario en el dashboard
  teniendo que buscar a mano el pedido que acababa de escanear.
- **`eliminarPago` no lleva la regla LIFO de 008.** Allá el monto de "saldo" se autocalcula a partir
  de los pagos previos y borrar uno intermedio dejaría a los posteriores describiendo un saldo que
  ya no existía; aquí cada pago lleva su propio monto capturado y ninguno depende de otro. Es además
  la vía por la que se corrige el cobro automático del escaneo.

### Lo que no se pudo verificar en vivo

- **El timbrado real de una autofactura**: no hay credenciales de Facturapi en este entorno (misma
  situación que 007 y 008). Las pruebas mockean `FacturapiService`.
- **El menú de compartir del sistema operativo** y la impresión de la etiqueta en una impresora de
  etiquetas: requieren un aparato real.
- **Las claves SAT genéricas de las líneas libres** (`01010101` / `H87`) no se han probado contra el
  SAT en un timbrado real.

## Criterios de aceptación

1. Se levanta un pedido capturando nombre, teléfono y correo, sin RFC, sin tocar el catálogo de
   Clientes.
2. Al pedido se le agregan artículos del catálogo y líneas libres escritas a mano, con cantidad y
   precio editables.
3. Al guardarlo se descuentan existencias solo de las líneas con artículo, y el folio es consecutivo
   e independiente de facturas y cotizaciones.
4. Se registra un anticipo con su cuenta y el pedido pasa a `anticipo`; el movimiento aparece en
   Tesorería y mueve el saldo de la cuenta.
5. El detalle muestra el ticket dibujado por el servidor, con el saldo pendiente correcto, y el
   botón "Compartir ticket" manda imagen y mensaje en celular, y descarga la imagen y copia el
   mensaje en escritorio.
6. El mensaje sale de Configuración con los huecos ya resueltos.
7. "Imprimir etiqueta" abre una etiqueta de 5 × 2.5 cm con nombre, no. de ticket, teléfono y QR, y
   nada más.
8. Escanear ese QR con la cámara del celular abre el sistema, cobra el saldo a la cuenta de efectivo
   y marca el pedido entregado, sin pedir confirmación, mostrando "Deshacer" durante 10 segundos.
9. Escanear dos veces no cobra dos veces.
10. "Deshacer" borra el pago automático, revierte el movimiento de Tesorería y regresa el pedido a su
    estado anterior.
11. Al quedar pagado, el detalle ofrece el enlace de autofactura y el botón que abre WhatsApp
    Desktop con el mensaje listo.
12. Abriendo ese enlace sin sesión, el cliente captura sus datos fiscales y obtiene su factura
    timbrada, que le llega por correo; el pedido queda ligado a ella y no descuenta inventario otra
    vez.
13. Un timbrado fallido le explica el motivo al cliente en español, le deja reintentar, y marca el
    pedido en el listado del usuario.
14. Cambiar el número del enlace de autofactura a mano no lleva a ningún otro pedido.
15. El enlace deja de funcionar al terminar el mes de la venta y cuando el pedido ya se facturó.

## Supuestos asumidos (registro completo)

Confirmados uno por uno con el usuario antes de redactar.

**Qué es este documento**

1. La venta de mostrador es un documento nuevo, "Pedido", con listado y folio propios — no un estado
   ni una variante de Cotización.
2. El "No. de ticket" de la etiqueta es el folio del pedido.
3. En el sistema se captura primero el pedido y al final el pago; que el cliente pague físicamente
   antes no cambia el orden de las pantallas.

**El cliente de mostrador**

4. Nombre, teléfono y correo se capturan dentro del pedido, sin RFC, sin dar de alta nada en el
   catálogo de Clientes.
5. Nombre y teléfono obligatorios, correo opcional. **El teléfono no se usa para mandar WhatsApp** —
   eso lo resuelve el botón compartir; sirve para identificar al cliente y para la etiqueta.
6. Si el teléfono coincide con el de una venta anterior, el sistema ofrece rellenar nombre y correo.

**Los artículos**

7. Además de los artículos del catálogo, se pueden agregar **líneas libres a mano** (descripción,
   cantidad y precio). No tocan inventario ni se guardan en el catálogo.
8. Cantidad y precio son editables línea por línea.
9. Aplica el descuento global; no aplica el descuento permanente de cliente.
10. La venta descuenta existencias al crear el pedido, no al entregarlo.

**El pago**

11. Los pagos usan la misma mecánica de Tesorería que los de cotización: monto, cuenta destino y su
    movimiento.
12. No hay porcentaje mínimo de anticipo.
13. Se pueden registrar varios pagos en el mismo pedido.
14. Estados: `pendiente` → `anticipo` → `pagado` → `entregado`.

**El ticket**

15. Imagen JPG vertical estilo tira de punto de venta, no PDF.
16. Contiene datos del negocio, no. de ticket, fecha y hora, cliente, líneas, total, pagado y saldo.
    Sin QR.
17. Se genera al guardar y se puede regenerar y recompartir en cualquier momento.
18. El botón compartir sigue el patrón de la ficha de artículo: celular, menú del sistema con imagen
    y texto; escritorio, descarga del JPG y copia del mensaje.
19. El mensaje que acompaña al ticket es editable desde Configuración.

**La etiqueta**

20. Documento aparte del ticket, se imprime desde el navegador.
21. Lleva nombre, no. de ticket, teléfono y QR; nada más.
22. Tamaño fijo de 5 × 2.5 cm.
23. El QR apunta a la pantalla del pedido dentro del sistema, protegida por login.
24. Se escanea con la cámara nativa del celular; no se construye un lector propio. **El formato del
    QR se elige para que la futura PWA con Capacitor pueda leerlo con su propio lector y cerrar el
    pedido en vivo** — esa PWA está fuera de alcance aquí.

**La entrega**

25. El escaneo **no pide confirmación**: cobra el saldo y marca entregado solo, con unos segundos de
    "Deshacer" por si se escaneó la etiqueta equivocada.
26. El saldo se da por pagado en **efectivo, a la cuenta de caja**, corregible después desde el
    pedido.
27. El enlace del QR requiere sesión; sin ella manda al login y regresa al pedido.

**La autofactura**

28. Enlace público, sin login, donde el cliente captura sus datos fiscales y el sistema timbra la
    factura del pedido con los mismos importes.
29. El enlace solo se habilita con el pedido totalmente pagado.
30. Vence el último día del mes de la venta.
31. Un pedido genera una sola factura; timbrada, el enlace deja de funcionar.
32. Al facturar, el cliente sí queda dado de alta en el catálogo de Clientes y se le manda la
    factura por correo.
33. El enlace se comparte con un botón que en Windows abre WhatsApp Desktop con el texto y el enlace
    prellenados.

**Transversal**

34. Nada se envía automáticamente: ticket, mensaje y enlace siempre los dispara el usuario.

**Adiciones técnicas aceptadas**

35. El ticket lo dibuja el **servidor**, para que salga idéntico desde cualquier aparato.
36. El mensaje admite **huecos que se rellenan solos**: `{nombre}`, `{folio}`, `{total}`,
    `{pagado}`, `{saldo}`.
37. El enlace de autofactura lleva una **clave larga al azar** de 64 caracteres, imposible de
    adivinar.
38. **Candado contra el doble escaneo** y "Deshacer" que revierte también el pago en Tesorería.
39. Si falla el timbrado: **motivo en español al cliente con reintento**, y **señal del fallo en el
    listado** del usuario.

**Decisiones de detalle tomadas al redactar** (no se consultaron una por una; se documentan para que
puedan corregirse antes de implementar)

40. **Cuál es "la caja"**: la cuenta de tipo `efectivo` más antigua del usuario. Si no hay ninguna,
    el escaneo entrega sin cobrar y lo avisa, en vez de bloquear la entrega del trabajo.
41. **La ventana del "Deshacer"** son 10 segundos en el botón y 5 minutos en el backend, para que un
    "Deshacer" disparado desde una pestaña olvidada no revierta mañana un cobro legítimo.
42. **Las líneas libres se timbran** con `clave_prod_serv` `01010101` y `clave_unidad` `H87`, porque
    el payload de Facturapi toma esas claves del artículo y una línea libre no tiene.
43. **La forma de pago del CFDI** se deriva del tipo de cuenta del último pago (`efectivo` → 01,
    `banco` y `digital` → 03, `otro` → 99); el cliente no la captura.
44. **El método de pago del CFDI es siempre PUE**, porque el enlace solo existe con el pedido
    totalmente pagado.
45. **La fuente del ticket** (`DejaVuSansMono`) se copia a `backend/resources/fonts/` en vez de
    leerse desde `vendor/dompdf`, para no atarse a la estructura interna de una dependencia.
46. **Las rutas públicas** (`autofactura/{token}`) llevan `throttle:20,1`: son las únicas del sistema
    que cualquiera en internet puede llamar.
