# Spec: PWA de mostrador (cuatro accesos, captura por pasos y escáner de etiquetas)

## Historia de usuario

Como usuario único del sistema, quiero una aplicación instalable en el celular, rápida, intuitiva y
de fácil acceso, con botones grandes para usarse con el dedo y **solo cuatro accesos**: generar
factura, generar cotización, generar venta al público y escanear etiquetas. Nada más que eso.

Tal como lo precisó el usuario al revisar las asunciones: **los demás módulos no se alcanzan por
ningún medio desde ese aparato**, porque se trabajan en la computadora.

## Objetivo / Alcance

La aplicación **ya es instalable**: el manifest, los iconos y el service worker existen desde que se
configuró `VitePWA` en `vite.config.ts`. Lo que esta spec agrega no es "hacerla PWA" sino las dos
cosas que faltan para que sirva en el mostrador:

1. **Un modo mostrador**: abierta desde el icono instalado, la aplicación muestra los cuatro accesos
   y **nada más** —sin menú y sin el resto de los módulos—; abierta en el navegador, sigue siendo el
   sistema completo de siempre.
2. **Cuatro caminos hechos para el dedo**: tres capturas por pasos (factura, cotización, venta) y un
   escáner de etiquetas que lee el QR con la cámara sin salir de la aplicación.

Es una spec **de solo frontend**. No agrega una tabla, una columna ni un endpoint: los cuatro
caminos se arman con lo que el backend ya expone.

### Qué ya existe y no se rehace

- **La instalación**: `VitePWA` con `registerType: 'autoUpdate'`, manifest con nombre, colores e
  iconos —incluido el `maskable` que Android recorta en círculo— y precache del shell sin
  `runtimeCaching`, para que ninguna respuesta autenticada quede guardada en disco.
- **La lectura de un QR con la cámara del aparato**: `leerQr()` en `lib/constanciaFiscal.ts`, que usa
  el detector nativo del navegador y devuelve `null` —sin lanzar— cuando el navegador no lo trae
  (ver [016](016-constancia-situacion-fiscal-qr.md)).
- **El alta de un cliente a partir de su constancia**: `POST clientes/constancia`, con su límite de
  10 peticiones por minuto, y el componente `ConstanciaFiscalDropzone`.
- **El compartir del sistema**: `compartirImagen` y `compartirTexto` en `lib/compartir.ts`, que en
  celular abren el menú del aparato (ver [020](020-imagenes-articulos.md) y
  [027](027-venta-mostrador-ticket.md)).
- **La entrega por escaneo**: `PedidoEntregaView` y `POST pedidos/{pedido}/entregar`, que cobra el
  saldo, marca entregado y admite deshacerse durante diez segundos (ver
  [027](027-venta-mostrador-ticket.md)).
- **El buscador de artículos** (`ArticuloBuscador`), el combo de clientes (`ClienteCombobox`), el
  ticket dibujado en el servidor y la sugerencia por teléfono (`GET pedidos/por-telefono`).

### Por qué el interruptor es la instalación y no el tamaño de la pantalla

El sistema tiene que decidir, al abrirse, si muestra cuatro botones o el sistema entero. Se decide
por **cómo se abrió la aplicación**: desde el icono instalado, o desde el navegador.

Es la señal que dice lo que el usuario realmente quiso. Instalar la aplicación en el celular del
mostrador es un acto deliberado —alguien la instaló ahí para vender—, mientras que el ancho de la
pantalla es un accidente: una ventana angosta en la computadora, o una tableta grande, caerían del
lado equivocado sin que nadie lo haya pedido. Tampoco es una dirección aparte, que habría que
escribir o guardar la primera vez y que se podría abrir por error desde la computadora.

**Consecuencia asumida**: si algún día la aplicación se instala también en la computadora, esa
instalación mostrará los cuatro accesos. Se acepta a cambio de no tener que mantener un ajuste ni
adivinar por el tamaño; desinstalarla, o abrirla desde el navegador, devuelve el sistema completo.

## Backend (Laravel)

**Ninguno.** No hay tabla, columna, endpoint ni validación nueva. Los cuatro caminos consumen lo que
ya existe:

| Camino     | Endpoints que usa                                                                        |
| ---------- | ---------------------------------------------------------------------------------------- |
| Venta      | `GET pedidos/por-telefono`, `POST pedidos`, `POST pedidos/{pedido}/pagos`, `GET pedidos/{pedido}/ticket` |
| Factura    | `GET clientes`, `POST clientes/constancia`, `POST clientes`, `POST facturas`, `POST facturas/{factura}/timbrar`, `POST facturas/{factura}/enviar-correo` |
| Cotización | `GET clientes`, `POST clientes/constancia`, `POST clientes`, `POST cotizaciones`, `POST cotizaciones/{cotizacion}/enviar` |
| Escáner    | `POST pedidos/{pedido}/entregar`, `POST pedidos/{pedido}/deshacer-entrega`                |

Que no haya backend nuevo es la prueba de que el modo mostrador es **otra forma de llegar** a lo que
el sistema ya hace, y no un sistema paralelo con sus propias reglas. Las validaciones, los folios,
los totales y el timbrado siguen siendo exactamente los mismos.

## Frontend (Vue 3)

### El modo mostrador

`lib/modoMostrador.ts` expone `enModoMostrador(): boolean`, que responde
`window.matchMedia('(display-mode: standalone)').matches`.

Se evalúa **una sola vez al arrancar** y el resultado se guarda: una ventana o se abrió desde el
icono o se abrió desde el navegador, y eso no cambia mientras esté abierta. Consultarlo en cada
repintado solo abriría la puerta a que media aplicación se dibujara de un modo y media del otro.

En desarrollo hace falta poder ver el modo mostrador sin instalar nada, así que
`?mostrador=1` en la dirección lo fuerza **solo cuando `import.meta.env.DEV`**. En producción el
parámetro se ignora: si sirviera, cualquiera podría dejarse encerrado en los cuatro accesos sin
saber cómo salir.

#### El candado de navegación

"No se llega por ningún medio" no se cumple escondiendo el menú: se cumple en el guard del router.
En modo mostrador, solo estas rutas son alcanzables:

- `dashboard` — los cuatro accesos.
- `mostrador-venta`, `mostrador-factura`, `mostrador-cotizacion`, `mostrador-escanear`.
- `pedidos-entregar` — el destino del QR de la etiqueta, sin el cual el cuarto acceso no serviría.
- `login`, `forgot-password`, `reset-password` — la puerta de entrada.

Cualquier otro nombre de ruta redirige a `dashboard`. Así, una dirección vieja guardada en el
navegador, un enlace pegado o un botón que quedó apuntando a una pantalla de escritorio terminan en
los cuatro accesos en vez de mostrar media aplicación que ahí no se puede usar.

Las rutas públicas que no pertenecen al sistema con sesión —`autofactura`— quedan fuera del candado:
las abre el cliente desde su propio teléfono y no tienen nada que ver con el aparato del mostrador.

#### La barra superior

`AppLayout` gana la propiedad `mostrador`. Con ella:

- **Desaparecen** el menú de grupos y el menú de usuario: son puertas a lo que el candado ya cierra,
  y dejarlas a la vista sería ofrecer lo que no se puede dar.
- **Queda** el nombre del sistema, que en las pantallas interiores funciona como botón de regreso a
  los cuatro accesos.
- **Cerrar sesión** baja al pie de la pantalla de los cuatro accesos, como un enlace discreto. Tiene
  que existir en algún lado —un aparato del que no se puede salir es un aparato prestado para
  siempre— pero no compite con los cuatro botones.

### La pantalla de los cuatro accesos

Vive en `/dashboard`, la misma dirección de hoy, y **la misma dirección muestra dos cosas distintas**:
en modo mostrador, los cuatro accesos; en el navegador, la pantalla de inicio actual tal como está.
Una sola dirección de inicio, sin una segunda que aprender.

- Cuatro botones en **cuadrícula de dos por dos**, cada uno con su ícono grande arriba y su texto
  debajo, y **toda la superficie del cuadro es tocable**, no solo las letras.
- Ocupan el alto disponible en partes iguales: en un celular de 375 puntos cada botón queda por
  encima de los 44 puntos de lado que se consideran el mínimo para el dedo, con margen de sobra.
- **Sin cifras, sin gráficas y sin pendientes.** Ni ventas del día, ni saldos, ni trabajos
  pendientes. Un número en esta pantalla invita a interpretarlo, y esta pantalla no es para leer: es
  para arrancar a trabajar en un toque.
- **Los cuatro son fijos**: no se reordenan, no se configuran y no cambian según lo que más se use.
  Un botón que se mueve solo obliga a mirar antes de tocar, que es justo lo que se quiere evitar.

| Acceso                  | Ícono              | Destino                  |
| ----------------------- | ------------------ | ------------------------ |
| Generar factura         | `DocumentTextIcon` | `/mostrador/factura`     |
| Generar cotización      | `DocumentDuplicateIcon` | `/mostrador/cotizacion` |
| Venta al público        | `TicketIcon`       | `/mostrador/venta`       |
| Escanear etiquetas      | `QrCodeIcon`       | `/mostrador/escanear`    |

Los íconos son los mismos con que cada módulo aparece en el menú de [013](013-navegacion-principal.md),
para que quien use las dos caras del sistema reconozca el mismo dibujo en las dos.

### La captura por pasos

Los tres formularios de hoy son la misma cosa: una **tabla ancha de renglones** con buscador de
artículo, precio, descuento, IVA y totales al pie (`DocumentoLineas`). Está hecha para teclado,
ratón y una pantalla grande; en un celular hay que arrastrarla de lado para ver las columnas.

El modo mostrador no la adapta: la reemplaza por **una pantalla por paso**, con un solo asunto a la
vez, un botón grande de "Siguiente" abajo y el paso actual señalado arriba. Adaptar la tabla habría
dejado la peor versión de las dos cosas —una tabla incómoda que además hay que mantener en dos
anchos—, y agrandar los campos de hoy no arregla que sean demasiados a la vez.

Los formularios de escritorio **no se tocan**: siguen siendo los de siempre para quien trabaja en la
computadora.

#### Lo que se comparte entre los tres

- **El paso de artículos** es el mismo componente en los tres: `ArticuloBuscador` arriba, y abajo la
  lista de lo agregado, un renglón por tarjeta con su cantidad en botones de "−" y "+", su precio y
  un botón de quitar. Sin tabla, sin columnas y sin descuentos por renglón: el descuento fino se
  aplica en la computadora.
- **Los renglones salen del catálogo.** La factura y la cotización ya lo exigen en el backend
  (`lineas.*.articulo_id` y `lineas.*.modelo` son obligatorios), así que en esos dos no hay renglón
  libre. La venta al público sí lo admite —es la línea libre de 027— y ofrece un botón de "Artículo
  suelto" para capturar descripción y precio a mano.
- **Los totales se calculan con `lib/totalesDocumento.ts`**, el mismo módulo que ya usan los
  formularios de escritorio. No se reimplementa la aritmética: un centavo de diferencia entre las
  dos caras del sistema sería imposible de explicarle a un cliente.
- **Se puede volver atrás** paso por paso sin perder lo capturado. Salirse de la captura a medias
  pide confirmación: en un celular el gesto de "atrás" está a un dedo de distancia todo el tiempo.

#### Venta al público — `/mostrador/venta`

Es el pedido de mostrador de [027](027-venta-mostrador-ticket.md), no un documento nuevo.

1. **Cliente** — teléfono y nombre. Al escribir el teléfono se consulta `GET pedidos/por-telefono` y,
   si ese número ya compró antes, se **ofrece** rellenar el nombre y el correo. Es una sugerencia que
   el usuario acepta con un toque, no un autocompletado que pisa lo que está escribiendo.
2. **Artículos** — el paso compartido, con línea libre.
3. **Cobro** — el total a la vista, el monto a cobrar ya escrito con el total (se puede bajar para
   registrar un anticipo) y la cuenta ya elegida: **la caja**, que es la cuenta de efectivo activa
   más antigua. Se puede cambiar a otra cuenta con un toque.

   Aquí sí se preselecciona, al revés que en la pantalla de entrega de
   [027](027-venta-mostrador-ticket.md), donde la cuenta se elige siempre. La diferencia es a quién
   se le está cobrando: la entrega cierra un pedido que pudo pagarse por transferencia días antes,
   mientras que esta pantalla cobra la venta que está ocurriendo enfrente, con el cliente pagando en
   el mostrador. Ahí la caja no es una comodidad que invite a confirmar por inercia: es lo que pasa
   casi siempre.
4. **Listo** — el ticket dibujado por el servidor, en grande, con **"Compartir por WhatsApp"**
   (`compartirImagen` con el mensaje de Configuración ya resuelto), más "Nueva venta" e "Inicio".

El paso de cobro hace **dos peticiones**: crea el pedido y luego registra el pago. Si la segunda
falla, el pedido **ya existe** y la pantalla lo dice con su número de ticket, ofreciendo reintentar
solo el cobro. Callarlo llevaría a capturar la venta otra vez y a terminar con dos pedidos por una
sola compra.

**La etiqueta adhesiva no se imprime aquí.** Un celular no tiene la impresora de etiquetas
conectada, y montar una cola de impresión hacia la computadora es un sistema entero por sí solo.
La venta queda registrada, cobrada y con su ticket en el teléfono del cliente; la etiqueta se
imprime desde la computadora como hasta hoy, y es esa etiqueta la que después se escanea.

#### Factura — `/mostrador/factura`

1. **Cliente** — buscador sobre el catálogo. Si no está, **"Escanear su constancia"**: la cámara lee
   el QR de la constancia de situación fiscal y el cliente queda dado de alta en el momento, con el
   mismo camino de [016](016-constancia-situacion-fiscal-qr.md). Un CFDI exige RFC, régimen y código
   postal correctos, y escribir esos tres a mano en un celular, con el cliente enfrente, es la
   manera más segura de timbrar mal.
2. **Artículos** — el paso compartido, solo del catálogo.
3. **Datos fiscales** — uso de CFDI, forma de pago y método de pago, que el backend exige
   (`uso_cfdi`, `forma_pago`, `metodo_pago`). Van en su propio paso, con los mismos valores por
   omisión del formulario de escritorio, para no mezclarlos con la captura de lo que se vende.
4. **Confirmar y timbrar** — nombre, RFC y total, grandes y sin nada más alrededor, y un solo botón:
   **"Timbrar"**.

El paso de revisión no es un trámite de más. Timbrar cuesta un folio, queda registrado ante la
autoridad y deshacerlo no es borrar sino cancelar con un motivo. Tres datos en una pantalla limpia
antes de apretar es barato comparado con una cancelación.

Después del timbrado, la pantalla de resultado muestra el folio fiscal y ofrece **"Enviar por
correo"** (`POST facturas/{factura}/enviar-correo`), "Nueva factura" e "Inicio". Sin ese botón, el
cliente se iría del mostrador con su factura timbrada y sin recibirla.

Si el timbrado falla, la factura **queda guardada** y la pantalla muestra el motivo con un botón de
reintentar, que es como se comporta el timbrado del escritorio.

#### Cotización — `/mostrador/cotizacion`

1. **Cliente** — igual que en la factura, incluido el alta escaneando la constancia. El backend exige
   `cliente_id` del catálogo fiscal, así que ese es el camino para cotizarle a alguien que no está.
2. **Artículos** — el paso compartido, solo del catálogo.
3. **Resumen y guardar** — el total y el botón de guardar.

La pantalla de resultado ofrece **"Enviar por WhatsApp"** (`POST cotizaciones/{cotizacion}/enviar`
con `canal: whatsapp`, teléfono ya puesto con el del cliente), "Nueva cotización" e "Inicio".

### El escáner de etiquetas — `/mostrador/escanear`

Hoy, para entregar un pedido, hay que salir a la app de cámara del celular, que abre la dirección de
la etiqueta en el navegador. El escáner elimina ese rodeo: la cámara se abre dentro de la aplicación.

- **La cámara trasera a pantalla completa**, con un recuadro guía al centro.
- **Captura sola.** No hay botón de disparo: se leen los cuadros de video hasta reconocer un código.
  Apuntar y esperar es lo único que se puede hacer con un paquete en la otra mano.
- **La lectura se extrae a `lib/lectorQr.ts`**, con `leerQr()` mudándose ahí desde
  `lib/constanciaFiscal.ts`, que pasa a importarlo. Dos pantallas leen el mismo tipo de código con el
  mismo detector, y una copia se habría quedado atrás la primera vez que se corrigiera un caso.
- **Qué se acepta**: el QR de la etiqueta codifica `{frontend_url}/pedidos/{id}/entregar` —lo escribe
  `Pedido::urlEntrega()`—. Se acepta un código **del mismo origen que la aplicación** y que calce con
  esa forma; de ahí sale el número de pedido y se navega a `pedidos-entregar` **dentro** de la
  aplicación, sin recargar nada.
- **Un código ajeno se ignora**: aviso corto sobre el video —"Ese código no es de una etiqueta del
  sistema"— y el escáner sigue trabajando. Nunca se abre una dirección de afuera: un QR pegado en
  cualquier caja podría llevar a donde sea, y el escáner de un punto de venta no es un navegador.
- **Un código repetido no se procesa dos veces** mientras la etiqueta siga frente a la cámara.

Al terminar la entrega, `PedidoEntregaView` en modo mostrador cambia su pie: en vez de "Ver el
pedido completo" —una pantalla que el candado no permite— ofrece **"Escanear otra"** e "Inicio". En
el mostrador se entregan varios trabajos seguidos y volver al inicio entre uno y otro son dos toques
por paquete.

**Lo que hace el escaneo no cambia.** Cobra el saldo, marca entregado y admite deshacerse durante
diez segundos, exactamente como manda 027. Esta spec cambia cómo se llega, no lo que pasa al llegar.

#### Las cuatro ayudas de la cámara

- **Linterna**: un botón prende el foco del aparato sin salir de la aplicación
  (`applyConstraints({ torch: true })`), para las etiquetas bajo la sombra del mostrador. Se muestra
  solo si la cámara del aparato lo soporta.
- **Pantalla despierta**: mientras el escáner está a la vista se pide que el aparato no se bloquee
  (Wake Lock). Sin eso hay que desbloquear el celular entre etiqueta y etiqueta. Se suelta al salir
  de la pantalla y se vuelve a pedir al regresar a ella, que es lo que exige esa API cuando el
  aparato se va a segundo plano.
- **Vibración**: un zumbido corto (`navigator.vibrate(60)`) al reconocer un código válido. Es la
  confirmación que no obliga a mirar la pantalla.
- **Respaldo por foto**: cuando la cámara en vivo no se puede abrir —permiso denegado, o un
  navegador que no da `getUserMedia`—, en vez de una pantalla muerta se ofrece **tomarle una foto a
  la etiqueta**, que se lee con el mismo detector. Es el camino para el aparato que sí trae detector
  pero no deja mirar por la cámara.

**Lo que el respaldo por foto no puede cubrir**: un navegador que no trae el detector tampoco puede
leer la foto, porque quien la leería es el detector que falta. Decodificar un QR sin él exigiría una
librería de terceros en el navegador —o una en PHP, porque el backend tampoco decodifica imágenes:
el `qr_url` de la constancia siempre lo lee el navegador (ver
[016](016-constancia-situacion-fiscal-qr.md))—, y ninguna de las dos entra en el alcance de esta
spec. Ahí la pantalla lo dice con todas sus letras y manda a abrir la etiqueta con la app de cámara
del teléfono, que es lo que se hacía antes de esta spec. Se acepta porque el aparato del mostrador
es Android, donde el detector existe (supuesto 25); el día que se sume un aparato con Safari, sumar
la librería es el cambio que toca, y es una spec nueva.

Los cuatro son opcionales por naturaleza: cada uno se ofrece solo si el aparato lo soporta y su
ausencia nunca rompe el escaneo.

### Instalación

- **Botón "Instalar aplicación"**, dentro del sistema y a la vista. Hoy instalar depende de encontrar
  "Instalar" en el menú del navegador, que casi nadie encuentra. El navegador avisa cuando la
  instalación es posible (`beforeinstallprompt`); se guarda ese aviso y el botón lo dispara.
  - Vive en la pantalla de inicio del navegador y **desaparece** cuando ya está instalada o cuando el
    navegador no ofrece instalarla. Un botón que no puede cumplir lo que promete es peor que ninguno.
- **Atajos en el icono**: dos entradas en el manifest (`shortcuts`), "Nueva venta" y "Escanear", para
  que al dejar el dedo apretado sobre el icono se entre directo sin pasar por los cuatro accesos.
- **Arranque limpio**: `start_url` apunta a `/dashboard`. La aplicación instalada **siempre abre en
  los cuatro accesos**, no en la última pantalla que se estaba viendo. Abrir el punto de venta y
  encontrarse a medio capturar la venta de ayer es peor que empezar de cero.

**Dependencia**: fuera de `localhost` el navegador **no registra el service worker ni ofrece
instalar sin HTTPS**. Hasta que exista el servidor de [022](022-subdominio-app.md) con su
certificado, el modo mostrador se puede construir y probar en `localhost` —que está exento— pero no
se podrá instalar en el celular del mostrador. Nada de lo que esta spec define depende de esa
migración salvo la instalación misma.

### Actualización

`registerType` pasa de `'autoUpdate'` a `'prompt'`, y un aviso discreto —una barra al pie con "Hay
una versión nueva" y un botón de recargar— aparece cuando hay una lista.

Hoy la actualización es silenciosa, lo que en la práctica significa que una pestaña abierta desde
ayer sigue con la versión vieja hasta que alguien la cierre, sin que nadie lo sepa. Un aparato de
mostrador se queda abierto días enteros: es justo donde ese silencio dura más. El aviso no
interrumpe —se puede seguir vendiendo con la versión que ya está cargada— pero deja de ser un
secreto.

### Sin conexión

- La aplicación **abre**: el shell está en el precache, así que arranca aunque no haya internet.
- En cuanto una pantalla necesita datos y no los consigue, muestra un **aviso claro del sistema** —"Sin
  conexión. Revisa el internet e inténtalo de nuevo"— con un botón de reintentar, en lugar de una
  pantalla en blanco o el error crudo del navegador.
- **Los datos siguen saliendo siempre de la red.** No se guarda nada del API en disco ni se capturan
  ventas para mandarlas después: una venta que existe solo en un celular no existe en la caja, y el
  precache de respuestas autenticadas es la forma clásica de servir datos viejos sin que nadie se
  entere. "Rápida" significa que la aplicación arranca al instante, no que trabaje sola.

### Sesión

Sin sesión iniciada, el guard sigue mandando al login y regresando a donde iba, igual que hoy.
En modo mostrador, "donde iba" es casi siempre `/dashboard`, y el escaneo de una etiqueta con la
sesión caída sigue llevando a la entrega después de entrar, que es lo que 027 ya resuelve.

### Rutas nuevas

```
/mostrador/venta        → mostrador-venta
/mostrador/factura      → mostrador-factura
/mostrador/cotizacion   → mostrador-cotizacion
/mostrador/escanear     → mostrador-escanear
```

Van bajo un prefijo común para que el candado del guard sea una sola regla y no una lista que haya
que recordar ampliar. **No son una puerta de entrada**: nadie las escribe ni las guarda; se llega a
ellas tocando los cuatro accesos. Quien las abra desde el navegador verá la captura por pasos, que
funciona igual pero está pensada para el dedo.

Ninguna ruta existente cambia de dirección ni de nombre.

## Fuera de alcance

- **Que la aplicación funcione sin internet**: capturar ventas desconectado y sincronizarlas después.
- **Imprimir la etiqueta adhesiva desde el celular**, en cualquier forma, incluida una cola de
  impresión hacia la computadora.
- **Editar o consultar documentos ya capturados** desde el modo mostrador: los cuatro accesos son
  para crear y para entregar, no para revisar. Consultar es trabajo de computadora.
- **Cobrar con terminal bancaria** o cualquier medio de pago que no sea registrar el monto.
- **Leer códigos de barras de artículos** para agregarlos a la venta. El catálogo no tiene códigos
  de barras capturados; el escáner solo lee el QR de las etiquetas del sistema.
- **Decodificar códigos QR sin el detector del navegador**, con una librería de terceros en el
  frontend o en PHP. El escáner descansa en el detector nativo, y donde no lo hay lo dice en vez de
  fingir que puede (ver "Las cuatro ayudas de la cámara").
- **Un quinto acceso**, de cualquier clase, y cualquier resumen, cifra o gráfica en la pantalla de
  inicio.
- **Notificaciones al celular** (push).
- **Publicar la aplicación en Google Play** o empaquetarla con Capacitor.
- **Cambiar los formularios de escritorio** de factura, cotización o venta.
- **La migración a `app.prosello.com.mx` con HTTPS**, que es la spec [022](022-subdominio-app.md)
  y de la que esta depende solo para poder instalarse.

## Criterios de aceptación

1. Abierta desde el icono instalado, la aplicación muestra **cuatro botones grandes y nada más**: sin
   menú de grupos y sin menú de usuario.
2. Abierta en el navegador, la aplicación es la de siempre, con su menú completo, y su pantalla de
   inicio se ve tal como está hoy.
3. En modo mostrador, escribir a mano la dirección de cualquier otra pantalla del sistema —artículos,
   cuentas, configuración— lleva a los cuatro accesos.
4. Los cuatro botones son tocables en toda su superficie y caben sin desplazar la pantalla en un
   celular de 375 puntos de ancho.
5. "Venta al público" captura por pasos, registra el pago y termina mostrando el ticket, con un botón
   que lo comparte por WhatsApp.
6. Si el pago falla después de haberse creado el pedido, la pantalla lo dice con el número de ticket y
   permite reintentar solo el cobro, sin capturar la venta otra vez.
7. "Generar Factura" termina en una pantalla de revisión con **nombre, RFC y total**, y desde ahí
   timbra. El resultado muestra el folio fiscal y permite enviarla por correo.
8. "Generar Cotización" permite dar de alta a un cliente **escaneando su constancia fiscal** cuando
   no está en el catálogo, y al guardar ofrece enviarla por WhatsApp.
9. Ningún importe capturado en el celular difiere de lo que calcularía el formulario de escritorio con
   los mismos datos.
10. "Escanear etiquetas" abre la cámara dentro de la aplicación y **captura sola**, sin botón de
    disparo.
11. Un QR que no es de una etiqueta del sistema no abre nada: avisa y el escáner sigue trabajando.
12. Al leer una etiqueta, el pedido se cobra y se entrega igual que hoy, con su "Deshacer" de diez
    segundos, y al terminar se puede escanear la siguiente sin volver al inicio.
13. La linterna, la pantalla despierta y la vibración funcionan donde el aparato las soporta, y su
    ausencia no impide escanear.
14. Con el detector disponible pero la cámara en vivo cerrada, el escáner ofrece tomar una foto de la
    etiqueta y la lee. Sin detector, dice que ese navegador no puede leer códigos y manda a abrir la
    etiqueta con la app de cámara, en vez de fallar sin explicación.
15. Existe un botón visible para instalar la aplicación, que desaparece una vez instalada.
16. La aplicación instalada abre siempre en los cuatro accesos.
17. Con una versión nueva desplegada, la aplicación abierta muestra un aviso con un botón de recargar.
18. Sin internet la aplicación abre y explica que no hay conexión, con un botón de reintentar, en vez
    de quedarse en blanco.
19. ESLint y Prettier corren sin errores sobre el código nuevo; `vitest` y `npm run build` en verde.

## Supuestos asumidos (registro completo)

Aprobados uno por uno con el usuario antes de redactar. Los que el usuario cambió van marcados como
**(redefinido)**.

**La pantalla de inicio**

1. Los cuatro accesos viven en la pantalla de inicio, en la misma dirección `/dashboard` de hoy.
2. **(Redefinido)** Los demás módulos **no se alcanzan por ningún medio** desde el aparato del
   mostrador, porque se trabajan en la computadora. (La propuesta original dejaba el menú completo a
   la vista y trataba los cuatro accesos como un simple atajo.)
3. La pantalla de inicio no muestra cifras ni gráficas: solo los cuatro accesos.
4. Los cuatro accesos son fijos: no se reordenan, no se configuran y no cambian con el uso.
5. **(Redefinido)** El celular y la computadora **no muestran lo mismo**: los cuatro accesos son
   exclusivos de la aplicación instalada, y el inicio del navegador se queda tal como está hoy. (La
   propuesta original era una sola pantalla igual en los dos lados.)
6. Cada botón lleva un ícono grande con su texto y toda su superficie es tocable.

**A dónde lleva cada acceso**

7. **(Redefinido)** "Generar Factura" **no** abre el formulario de escritorio: abre una captura por
   pasos, que termina en una pantalla de revisión con nombre, RFC y total y ahí timbra. (La propuesta
   original abría `/facturas/crear` tal como está.)
8. **(Redefinido)** "Generar Cotización" **no** abre el formulario de escritorio: abre una captura por
   pasos, y al cliente que no está en el catálogo se le da de alta **escaneando su constancia
   fiscal**. (La propuesta original abría `/cotizaciones/crear` tal como está.)
9. **(Redefinido)** "Venta al público" sí es el pedido de mostrador de 027, pero **no** abre su
   formulario de escritorio: abre una captura por pasos que termina cobrando y compartiendo el
   ticket. La etiqueta se sigue imprimiendo desde la computadora. (La propuesta original abría
   `/pedidos/crear` tal como está.)
10. "Escanear etiquetas" abre una pantalla nueva con la cámara dentro de la aplicación.

**El escáner**

11. Lee el QR de la etiqueta de 027 y lleva a la entrega de ese pedido, sin salir a la app de cámara.
12. La cámara captura sola; no hay que apretar nada.
13. Un QR ajeno se ignora con un aviso corto y el escáner sigue trabajando.
14. Al terminar una entrega se puede volver a escanear de inmediato.
15. El escaneo sigue haciendo lo que manda 027 —cobra el saldo y entrega—; esta spec solo cambia cómo
    se llega.

**Instalación y velocidad**

16. La aplicación instalada abre siempre en los cuatro accesos, no en la última pantalla vista.
17. "Rápida" es que arranca sin volver a descargar su interfaz. No trabaja sin internet: los datos
    siempre salen de la red.
18. Sin conexión, la aplicación abre y da un aviso claro, no una pantalla en blanco.
19. Sin sesión sigue mandando al login y regresando a donde iba, igual que hoy.

**Preguntas resueltas al ajustar los supuestos redefinidos**

20. **El interruptor es la aplicación instalada**, no el tamaño de la pantalla, ni una dirección
    aparte, ni un ajuste en Configuración.
21. **El inicio de la computadora se queda como está**: los cuatro accesos son exclusivos de la
    aplicación instalada.
22. **La captura de los tres documentos es por pasos**, una pantalla por paso, y no una sola pantalla
    larga, ni una captura mínima que se termina en la computadora, ni los formularios de hoy
    agrandados.
23. **La factura llega hasta el timbrado**, con un paso de revisión antes.
24. **Al cliente que no está en el catálogo se le escanea la constancia fiscal.**
25. **El aparato del mostrador es Android**, así que el detector de códigos del navegador está
    disponible. Sobre eso descansa que el respaldo por foto no tenga que cubrir la ausencia del
    detector.
26. **La venta al público termina cobrando y compartiendo el ticket**; la etiqueta se imprime desde la
    computadora.

**Adiciones técnicas aceptadas**

27. Respaldo por foto cuando la cámara en vivo no se puede abrir.
28. Linterna dentro del escáner.
29. La pantalla no se apaga mientras el escáner está abierto.
30. Vibración corta al leer un código válido.
31. Atajos "Nueva venta" y "Escanear" colgados del icono instalado.
32. Botón "Instalar aplicación" dentro del sistema.
33. Aviso de versión nueva, en vez de la actualización silenciosa de hoy.

*(Se retiró una adición propuesta —una página propia de "sin conexión"— por quedar cubierta por el
supuesto 18.)*

**Decisiones de detalle tomadas al redactar** (no se consultaron una por una; se documentan para que
puedan corregirse antes de implementar)

34. **El candado vive en el guard del router**, no en esconder el menú: "no se llega por ningún
    medio" tiene que cumplirse también para una dirección escrita a mano o guardada de antes.
35. **`pedidos-entregar` es la única pantalla de escritorio que el candado deja pasar**, porque es el
    destino del QR y sin ella el cuarto acceso no serviría de nada.
36. **Cerrar sesión baja al pie de la pantalla de los cuatro accesos.** Tiene que existir en algún
    lado, pero no compite con los cuatro botones.
37. **El alta por constancia también se ofrece en la factura**, no solo en la cotización: la factura
    exige los mismos datos fiscales y con más razón.
38. **La pantalla de resultado de la factura ofrece enviarla por correo**, y la de la cotización
    enviarla por WhatsApp, reusando endpoints que ya existen. Sin eso el cliente se iría del
    mostrador sin recibir su documento.
39. **El paso de cobro de la venta preselecciona la caja** —la cuenta de efectivo activa más
    antigua— y deja cambiarla. La pantalla de entrega de 027 no preselecciona nada, y es una
    diferencia buscada: allá se cierra un pedido que pudo pagarse por transferencia días antes, acá
    se cobra la venta que está ocurriendo enfrente.
40. **`leerQr()` se muda a `lib/lectorQr.ts`** y la constancia fiscal pasa a importarlo desde ahí:
    dos pantallas leen el mismo tipo de código y una copia se quedaría atrás.
41. **El escáner solo acepta códigos del mismo origen que la aplicación.** Un QR pegado en cualquier
    caja podría llevar a donde sea, y el escáner de un punto de venta no es un navegador.
42. **`?mostrador=1` fuerza el modo solo en desarrollo.** En producción se ignora, para que nadie
    quede encerrado en los cuatro accesos sin saber cómo salir.
43. **El modo se evalúa una sola vez al arrancar.** Una ventana o se abrió desde el icono o no; leerlo
    en cada repintado solo abriría la puerta a que media aplicación se dibujara de cada modo.
44. **Instalar la aplicación en la computadora la pondría en modo mostrador.** Es la consecuencia
    aceptada de elegir la instalación como interruptor; abrirla desde el navegador devuelve el
    sistema completo.
