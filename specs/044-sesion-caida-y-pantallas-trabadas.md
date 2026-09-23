# Spec: Sesión caída y pantallas que se quedan trabadas o en blanco

## Historia de usuario

Como usuario quiero que el sistema no se quede con pantallas trabadas o en blanco, y que cuando mi
sesión se caiga me lleve al login diciéndomelo, en vez de dejarme mirando una pantalla que nunca
termina de cargar.

**Síntoma reportado:** "El sistema me sigue dando problemas al cargar las páginas. En ocasiones se
quedan trabadas o en blanco", acompañado en la consola del navegador de:

```
api/v1/user:1  Failed to load resource: the server responded with a status of 401 ()
```

## Objetivo / Alcance

Arreglar las **tres causas distintas** que producen ese mismo síntoma. Son independientes entre sí
y cada una se puede verificar por separado:

1. **La sesión del servidor muere a las 2 horas aunque se haya marcado "recordarme"** — el origen
   del `401`.
2. **Cuando la sesión se cae a media navegación, nada manda al login** — el origen de la pantalla
   trabada.
3. **Un `index.html` viejo servido por el service worker deja rutas que no cargan nunca** — el
   origen de la pantalla en blanco y de las recargas que no arreglan nada.

### Lo que NO es un problema: el mensaje de `beforeinstallprompt`

```
Banner not shown: beforeinstallpromptevent.preventDefault() called.
```

Es esperado y deliberado: `src/lib/instalacion.ts` llama `preventDefault()` para suprimir la franja
propia del navegador y ofrecer en su lugar el botón "Instalar" del sistema
(ver [029-pwa-mostrador.md](029-pwa-mostrador.md)). El navegador lo anuncia en consola siempre que
alguien hace eso. **No se toca nada por este mensaje** y no tiene relación con las pantallas
trabadas; queda escrito aquí para no volver a investigarlo.

---

## Causa 1: "Recordarme" alarga la cookie, pero no la sesión del servidor

### El defecto

[002-login-auth.md](002-login-auth.md) decidió implementar "recordarme" con un middleware propio,
`ExtendSessionLifetimeWhenRemembered`, que sobreescribe `session.lifetime` a 30 días **solo durante
el POST de login**, en vez de usar el mecanismo nativo de Laravel. Esa decisión quedó registrada en
el supuesto 19 de esa spec:

> se sobreescribe dinámicamente `session.lifetime` a 30 días antes de crear la sesión cuando el
> checkbox está marcado, en vez de usar la cookie `remember_token` clásica de Laravel — así la
> cookie de sesión misma dura 30 días

**El supuesto es incorrecto con `SESSION_DRIVER=database`**, que es el driver que se usa tanto en
desarrollo como en producción (ver [018-despliegue-hostinger.md](018-despliegue-hostinger.md)).
Alargar la cookie no alarga la sesión: quien decide si la sesión sigue viva es el servidor, y lo
decide leyendo `config('session.lifetime')` **en cada petición**, no en la del login:

```php
// vendor/laravel/framework/src/Illuminate/Session/DatabaseSessionHandler.php:119-123
protected function expired($session)
{
    return isset($session->last_activity) &&
        $session->last_activity < Carbon::now()->subMinutes($this->minutes)->getTimestamp();
}
```

`$this->minutes` se construye a partir de `config('session.lifetime')`, que vale **120** en toda
petición que no sea el login, porque el middleware solo actúa sobre `POST api/v1/auth/login`. El
recolector de basura (`gc()`) usa el mismo número y **borra el renglón** de la tabla `sessions`.

Consecuencia exacta, y es justo lo que el usuario reporta: a las 2 horas de inactividad el
navegador sigue mandando una cookie perfectamente válida (le quedan 30 días de vida), el servidor
ya no encuentra la sesión, y `GET api/v1/user` responde **401**. La verificación end-to-end de 002
—`Max-Age` 7200 → 2592000— comprobó lo único que sí funcionaba, la cookie, y por eso el defecto
pasó desapercibido.

Un aparato de mostrador, que se queda abierto días enteros
([029-pwa-mostrador.md](029-pwa-mostrador.md)), lo pega varias veces al día.

### La corrección: usar el "recordarme" nativo de Laravel

Se abandona el middleware propio y se vuelve al mecanismo que Laravel ya trae, que es el que
sobrevive a la expiración de la sesión: la cookie *recaller* (`remember_web_*`). Cuando la sesión
del servidor expira, `SessionGuard` encuentra esa cookie, reautentica al usuario contra
`users.remember_token`, regenera la sesión y la petición continúa como si nada. La columna
`remember_token` ya existe desde la migración original de `users`.

- **`app/Http/Requests/Auth/LoginRequest.php`**: `Auth::attempt()` recupera su segundo argumento,
  `$this->boolean('remember')`. Se quita el comentario que explicaba por qué no se usaba.
- **`app/Http/Controllers/Auth/AuthenticatedSessionController::store()`**: antes de autenticar,
  fija la duración del recuerdo en 30 días para no heredar el valor por defecto de Laravel
  (~400 días), que sería mucho más de lo que 002 le prometió al usuario:
  `Auth::guard('web')->setRememberDuration(60 * 24 * 30)`.
- **`app/Http/Middleware/ExtendSessionLifetimeWhenRemembered.php`**: se elimina, junto con su
  registro en `bootstrap/app.php`. Deja de tener razón de ser, y mantenerlo sería volver a prometer
  30 días de cookie que el servidor no respeta.
- **`SESSION_LIFETIME` se queda en 120** en `.env` y en `deploy/hostinger/env.production.example`.
  Con el recuerdo nativo funcionando, ese número vuelve a significar lo que debe significar —cuánto
  dura una sesión de alguien que **no** marcó "recordarme"— y ya no hay motivo para inflarlo.

Quien no marque "recordarme" sigue perdiendo la sesión a las 2 horas de inactividad, exactamente
como hoy y como 002 quería. La diferencia es que ahora eso lo lleva al login (causa 2) en vez de
dejarlo trabado.

### Por qué no se arregla "extendiendo el lifetime en cada petición"

La alternativa —guardar una marca de "este usuario marcó recordarme" y volver a subir
`session.lifetime` en cada petición— no se puede implementar bien: la marca tendría que vivir
dentro de la sesión, pero el handler necesita saber el lifetime **antes** de leer la sesión para
decidir si está expirada. Sería una cookie extra propia, replicando a mano lo que la cookie
*recaller* de Laravel ya hace bien y con rotación de token incluida.

---

## Causa 2: cuando la sesión se cae a media navegación, nada manda al login

### El defecto

El guard del router consulta `/user` **una sola vez en toda la vida de la pestaña**:

```ts
// src/router/index.ts:397
if (!auth.initialized) {
  await auth.fetchUser()
}
```

`initialized` nunca vuelve a `false`, y `src/lib/http.ts` no tiene ningún interceptor de respuesta.
Entonces, con la sesión ya muerta en el servidor pero la pestaña todavía abierta: `auth.user` sigue
en memoria → el guard deja pasar → la vista monta → **cada petición responde 401** → la pantalla se
queda con su spinner o vacía, o muestra "Unauthenticated." en el recuadro de error, y **nunca**
redirige al login. El usuario no tiene forma de saber que lo único que hace falta es recargar.

Hay un segundo defecto en el mismo lugar: `fetchUser()` atrapa **todos** los errores por igual
(`catch { this.user = null }`), sin distinguir un 401 —no hay sesión— de un fallo de red —no se
pudo preguntar—. En un mostrador con wifi intermitente, un `/user` que no llega a ningún lado
"deslogea" visualmente al usuario y lo manda al login aunque su sesión esté perfectamente viva. El
propio `src/lib/errors.ts` ya tiene el predicado que falta usar aquí: `esErrorSinConexion()`.

### La corrección

**`src/lib/http.ts` — interceptor de respuesta.** Se agrega un interceptor que reconoce la sesión
caída y la convierte en una ida al login, en vez de dejar la pantalla muerta:

- **419 (token CSRF vencido):** cuando la sesión expira, la cookie `XSRF-TOKEN` se va con ella, así
  que el primer POST después de la expiración no falla con 401 sino con **419**. El interceptor
  pide una cookie CSRF nueva (`ensureCsrfCookie()`) y **reintenta la petición original una sola
  vez**, marcándola para no reintentarla en cadena. Con el recuerdo nativo de la causa 1, ese
  reintento normalmente sale bien y el usuario no se entera de nada.
- **401 (sin sesión):** se limpia el store de auth (`user = null`, `initialized = false`) y se
  olvidan las listas del mostrador —lo mismo que ya hace `logout()`, porque los datos cargados
  pertenecen a la sesión que acaba de morir (ver [031-mostrador-consulta.md](031-mostrador-consulta.md))—
  y se navega a `login` con `redirect` a donde se estaba y `expirada=1`.

El interceptor **solo actúa cuando hay algo que rescatar**, es decir cuando la ruta actual declara
`requiresAuth` y el store todavía cree tener usuario. Esa condición sola resuelve los tres casos en
los que redirigir sería un error, sin necesidad de listas de rutas exentas:

- El `/user` del guard en un arranque sin sesión: `auth.user` ya es `null`, así que el interceptor
  no hace nada y el guard sigue mandando al login como siempre.
- El portal público de autofacturación (`/autofactura/:token`), que usa el mismo cliente `http` y
  lo abre un cliente **que no tiene cuenta** ([029](029-pwa-mostrador.md)): su ruta no declara
  `requiresAuth`, así que nunca se le manda a un login que no le sirve.
- La propia pantalla de login y las demás `guestOnly`: tampoco declaran `requiresAuth`, así que no
  hay bucle de redirección.

**`src/stores/auth.ts` — `fetchUser()` distingue no-sesión de no-red.** Un 401 sigue dejando
`user = null`. Un error sin respuesta (`esErrorSinConexion()`) **conserva** el usuario que hubiera
y deja `initialized` en `false`, para que el guard vuelva a intentarlo en la siguiente navegación
en vez de dar la sesión por perdida por un bache de wifi. Que `initialized` sea ahora reseteable es
lo que permite además que el interceptor fuerce una reconsulta.

**`src/views/LoginView.vue` — decir por qué se está ahí.** Con `?expirada=1` en la URL, el login
muestra un aviso ("Tu sesión expiró. Entra de nuevo para continuar.") sobre el formulario. Sin ese
aviso, la redirección automática se siente como que el sistema se salió solo. El parámetro
`redirect` ya existente sigue devolviendo al usuario a donde iba, con la validación de ruta interna
que ya tiene.

---

## Causa 3: un `index.html` viejo deja rutas que no cargan nunca

### El defecto

`src/main.ts` ya contempla que una pestaña abierta durante un despliegue se quede pidiendo chunks
que ya no existen, y recarga una vez:

```ts
const CLAVE_RECARGA = 'recarga-por-chunk-faltante'
window.addEventListener('vite:preloadError', (event) => {
  if (sessionStorage.getItem(CLAVE_RECARGA)) return
  event.preventDefault()
  sessionStorage.setItem(CLAVE_RECARGA, '1')
  window.location.reload()
})
router.isReady().then(() => sessionStorage.removeItem(CLAVE_RECARGA))
```

Tiene dos fallas que se combinan:

1. **La recarga no cambia nada cuando hay service worker.** `window.location.reload()` salta la
   caché HTTP pero **no** al service worker, que sigue sirviendo el `index.html` viejo de su
   precache. Y con `registerType: 'prompt'` ([029](029-pwa-mostrador.md), una decisión correcta que
   esta spec no toca) el service worker nuevo puede quedarse esperando indefinidamente mientras el
   usuario siga dándole a "Ahora no". La recarga devuelve exactamente la misma página rota.
2. **La bandera se limpia demasiado pronto.** `router.isReady()` resuelve cuando termina la
   navegación **inicial**, que casi nunca es la ruta cuyo chunk falta. Entonces la bandera queda
   limpia y cada intento de entrar a la pantalla rota vuelve a recargar la página entera y vuelve a
   no llegar: se ve exactamente como una pantalla que se queda trabada. Y en el caso en que sí es
   la ruta inicial la que falla, al segundo intento el `return` temprano deja pasar el error sin
   `preventDefault()`, la navegación del router se rechaza y **no queda nada montado: pantalla en
   blanco**, sin mensaje.

### La corrección

**Antes de recargar, activar el service worker nuevo.** Se extrae el registro del service worker a
`src/lib/actualizacion.ts`, que llama `registerSW` de `virtual:pwa-register` y expone dos cosas:
`needRefresh` (la que consume hoy `AvisoActualizacion.vue`) y `aplicarActualizacion()`, que hace
`skipWaiting` + recarga. `AvisoActualizacion.vue` pasa a consumir ese módulo en vez de registrar el
service worker por su cuenta, para que no haya dos registros. El manejador de `vite:preloadError`
llama `aplicarActualizacion()` en lugar de `window.location.reload()`: así la recarga sí llega con
el `index.html` nuevo.

**La bandera se ata al build, no a la navegación inicial.** `vite.config.ts` inyecta un
identificador de compilación (`define: { __BUILD_ID__: ... }`, una marca de tiempo del build). Al
recargar se guarda ese identificador en `sessionStorage`; al volver a fallar se compara:

- Si el identificador guardado es **distinto** del actual, la recarga sí trajo una versión nueva y
  el fallo es otro: se permite un intento más.
- Si es **el mismo**, recargar ya se demostró inútil. No se recarga: se deja que el error llegue al
  router.

**Y si de todos modos no se puede, decirlo en vez de quedarse en blanco.** Se agrega
`router.onError()`: ante un fallo de carga de módulo que ya no se va a reintentar, se muestra un
mensaje a pantalla completa ("No se pudo cargar esta pantalla. Recarga la página.") con un botón
que fuerza la recarga. Una pantalla en blanco sin explicación es el peor resultado posible y hoy es
el que se obtiene.

---

## Backend (Laravel)

- `app/Http/Requests/Auth/LoginRequest.php`: `Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))`.
- `app/Http/Controllers/Auth/AuthenticatedSessionController.php`: `setRememberDuration(60 * 24 * 30)`
  sobre el guard `web` antes de `$request->authenticate()`.
- `app/Http/Middleware/ExtendSessionLifetimeWhenRemembered.php`: **eliminado**.
- `bootstrap/app.php`: se quita del `prepend` del grupo `api`; `EnsureFrontendRequestsAreStateful`
  se queda solo, en el mismo lugar.
- Nada más del backend cambia. `SESSION_LIFETIME`, `SESSION_DRIVER`, `SESSION_DOMAIN` y
  `SANCTUM_STATEFUL_DOMAINS` se quedan exactamente como están, en desarrollo y en producción.

## Frontend (Vue 3)

- `src/lib/http.ts`: interceptor de respuesta (reintento único ante 419 tras refrescar CSRF; 401 →
  limpiar sesión y navegar al login con `redirect` y `expirada`), activo solo cuando la ruta actual
  declara `requiresAuth` y el store todavía cree tener usuario.
- `src/stores/auth.ts`: `fetchUser()` distingue 401 de fallo de red; `initialized` vuelve a ser
  reseteable.
- `src/views/LoginView.vue`: aviso de sesión expirada cuando llega `?expirada=1`.
- `src/lib/actualizacion.ts` (**nuevo**): registro único del service worker; expone `needRefresh` y
  `aplicarActualizacion()`.
- `src/components/AvisoActualizacion.vue`: consume `src/lib/actualizacion.ts` en vez de
  `useRegisterSW` directo. El aviso y su texto no cambian.
- `src/main.ts`: `vite:preloadError` usa `aplicarActualizacion()` y la bandera atada al build id;
  `router.onError()` para el mensaje de pantalla que no carga.
- `vite.config.ts`: `define` con el identificador de compilación. La configuración de `VitePWA`
  —`registerType: 'prompt'`, `workbox`, `manifest`— **no se toca**.

## Fuera de alcance

- **Cambiar `registerType: 'prompt'` por `autoUpdate`.** La razón por la que 029 lo eligió sigue
  siendo válida: un aparato de mostrador abierto días enteros no debe cambiar de versión a media
  venta sin que nadie lo sepa. Esta spec hace que la versión vieja deje de romper la navegación,
  no que el aviso desaparezca.
- **Renovación silenciosa de sesión, *refresh tokens* o tokens de API.** El sistema sigue siendo
  Sanctum con sesión de cookie, igual que desde [002](002-login-auth.md).
- **Subir `SESSION_LIFETIME`.** Sería tapar la causa 1 en vez de arreglarla, y le quitaría sentido
  al checkbox "recordarme".
- **`runtimeCaching` de las respuestas del API.** Sigue deliberadamente ausente por lo que dice
  [029](029-pwa-mostrador.md): cachear respuestas autenticadas es servir datos viejos, o de otro
  usuario, desde el disco.
- **Un sistema global de avisos (*toasts*).** El proyecto no tiene uno y esta spec no es el lugar
  para introducirlo; el aviso de sesión expirada vive en el login, donde el usuario ya aterrizó.
- **Cerrar sesión en las demás pestañas abiertas** cuando una detecta el 401.
- **El mensaje de `beforeinstallprompt`** (ver arriba: es esperado).

## Estado de implementación

Implementada el 2026-09-23.

- **Backend, tal como planeaba la spec**: `Auth::attempt()` recupera su segundo argumento,
  `AuthenticatedSessionController` fija `setRememberDuration(60 * 24 * 30)` **antes** de autenticar
  (la cookie recaller se emite dentro de `attempt()`, con la duración que el guard tenga en ese
  instante), y `ExtendSessionLifetimeWhenRemembered` se eliminó junto con su registro en
  `bootstrap/app.php`. `app/Http/Middleware/` quedó vacío.
- **Frontend, tal como planeaba la spec**: `src/lib/actualizacion.ts` (nuevo, registro único del
  service worker), interceptor en `src/lib/http.ts`, `fetchUser()` y `marcarSesionCaida()` en el
  store, aviso de sesión expirada en el login, `vite:preloadError` + `router.onError()` en
  `main.ts`, `define.__BUILD_ID__` en `vite.config.ts` y su declaración en `src/env.d.ts` (nuevo).
- **`http.ts` no importa el router ni el store**: expone `registrarSesionCaida()` y es `main.ts`
  quien decide qué hacer. Importarlos habría cerrado un ciclo (router → store → http → router).
- **`useRegisterSW` se llama fuera de un componente** en `actualizacion.ts`. Se comprobó en el
  código del plugin que solo usa `ref()`, sin hooks de ciclo de vida, así que es legítimo.
- **`aplicarActualizacion()` recarga por su cuenta tras 1 segundo.** Al leer el plugin se vio que
  `updateServiceWorker(true)` **no recarga**: solo manda `skipWaiting`, y la recarga la dispara
  después el evento `controlling`, que únicamente existe si había una versión esperando. Sin ese
  respaldo, un chunk faltante sin actualización pendiente se habría quedado sin recargar nunca —
  justo el caso que la spec quería cerrar.
- **`router.replace()` del interceptor lleva `.catch()`**: si había una navegación en curso, el
  router la rechaza, y esa promesa suelta habría vuelto a ensuciar la consola.
- **Pruebas nuevas** en `tests/Feature/Auth/AuthenticationTest.php` (5): el recuerdo revive una
  sesión ya expirada, sin "recordarme" no revive, el recuerdo dura 30 días y no ~400, cerrar sesión
  invalida también el recuerdo, y cambiar la contraseña lo invalida en otros aparatos.
- **Dos trampas del entorno de pruebas, ninguna de producción**, que costaron encontrar y quedan
  documentadas en el propio archivo de tests: (1) `withCookie()` **cifra** lo que se le pasa, así
  que reenviar el valor tal como viaja en la respuesta lo dejaba cifrado dos veces y
  `EncryptCookies` lo descartaba en silencio —hay que tomarlo descifrado con
  `$respuesta->getCookie()`—; y (2) `getJson()` **no manda cookies** salvo que se pida
  `withCredentials()`. Además, `forgetInstance()` no basta para simular una petición nueva: las
  fachadas guardan su propia copia resuelta y middleware como `guest` consultan `Auth::` por ahí,
  así que hizo falta `Facade::clearResolvedInstances()`.
- **Verificación**: la suite Pest completa pasa (697 tests, antes 692); Pint limpio sobre los
  archivos tocados. En frontend, `vue-tsc -b && vite build` compila sin errores de tipos, Vitest
  (96 tests) y ESLint pasan limpios y Prettier no tuvo nada que corregir.
- **No se verificó en un navegador real** (misma limitación de entorno que el resto de las
  historias). Quedan pendientes de comprobar ahí: el criterio 6 (la redirección al login con su
  aviso al caer la sesión), el 7 (reintento de CSRF al guardar), el 10 (bache de red que no saca al
  usuario) y los criterios 11 a 14, que además exigen un despliegue real de por medio. Los
  criterios 1 a 5 sí quedan cubiertos por las pruebas nuevas.

## Criterios de aceptación

1. Con "recordarme" marcado, tras **más de 2 horas** sin actividad (el `SESSION_LIFETIME` de 120
   minutos), la primera petición al API vuelve a responder con la sesión del usuario: no hay 401 y
   el usuario sigue dentro.
2. Con "recordarme" marcado, la sesión sigue viva al día siguiente y hasta los 30 días; pasados los
   30 días sí se pide entrar de nuevo.
3. **Sin** "recordarme", tras más de 2 horas sin actividad la sesión sí caduca —igual que hoy— y el
   usuario termina en el login con el aviso de sesión expirada, no en una pantalla trabada.
4. Cerrar sesión explícitamente deja al usuario fuera de verdad: volver atrás en el navegador o
   pedir cualquier pantalla con sesión lleva al login, sin que la cookie de recuerdo lo vuelva a
   meter.
5. Cambiar la contraseña desde "olvidé mi contraseña" invalida el recuerdo: las sesiones recordadas
   en otros aparatos dejan de entrar solas.
6. Con la sesión caída y la pestaña abierta, navegar a cualquier pantalla del sistema lleva al
   login con el aviso "Tu sesión expiró…"; al entrar, se vuelve a la pantalla que se pedía.
7. Con la sesión caída, **guardar** un formulario (POST) no deja el formulario colgado: o el
   reintento tras refrescar el CSRF sale bien y guarda, o se termina en el login con lo mismo del
   punto 6.
8. El portal público de autofacturación (`/autofactura/:token`) nunca redirige al login, pase lo
   que pase con sus peticiones: quien lo abre no tiene cuenta.
9. Abrir el sistema sin ninguna sesión sigue llevando al login directo, sin aviso de sesión
   expirada y sin bucles de redirección.
10. Una caída de red momentánea (modo avión unos segundos) **no** saca al usuario al login: al
    volver la red, la misma pestaña sigue trabajando con su sesión.
11. Tras un despliegue nuevo, una pestaña que quedó abierta con la versión anterior puede navegar a
    una pantalla cuyo chunk ya no existe: se recarga una vez tomando la versión nueva y la pantalla
    aparece.
12. Esa recarga ocurre **una sola vez**: no hay una segunda recarga por el mismo motivo si la
    primera no cambió de versión.
13. Si aun así la pantalla no puede cargar, se ve un mensaje que lo dice y un botón para recargar —
    nunca una pantalla en blanco.
14. El aviso "Hay una versión nueva del sistema" con sus botones "Recargar" y "Ahora no" se
    comporta igual que antes de esta spec.
15. `GET api/v1/user` sigue respondiendo 401 —y no un redirect— cuando de verdad no hay sesión: es
    una API pura y `redirectGuestsTo(null)` no cambia.

## Supuestos asumidos (registro completo)

1. El `401` reportado en consola es el de `fetchUser()` del guard y es **correcto** en sí mismo: el
   defecto no es que se responda 401, sino que la sesión muera a las 2 horas teniendo "recordarme"
   marcado, y que ese 401 no lleve a ninguna parte.
2. "Recordarme" pasa a implementarse con el mecanismo nativo de Laravel (cookie *recaller* +
   `users.remember_token`), revirtiendo el supuesto 19 de
   [002-login-auth.md](002-login-auth.md), que se da por **equivocado** para
   `SESSION_DRIVER=database`.
3. La duración de "recordarme" se fija explícitamente en **30 días**, el número que 002 le prometió
   al usuario, en vez del valor por defecto de Laravel (~400 días).
4. `SESSION_LIFETIME` se queda en 120 minutos para quien no marca "recordarme". No se sube.
5. El interceptor de 401 actúa **solo** si la ruta actual declara `requiresAuth` y el store todavía
   cree tener usuario; esa sola condición cubre los casos del login, del arranque sin sesión y del
   portal público de autofacturación.
6. Un 419 se trata como sesión caída recuperable: se refresca el CSRF y se reintenta la petición
   original **una vez**. Si el reintento vuelve a fallar, se trata como 401.
7. Al detectar la sesión caída se olvidan las listas del mostrador, igual que en `logout()`, porque
   pertenecen a la sesión que acaba de morir ([031](031-mostrador-consulta.md)).
8. Un fallo **sin respuesta** (sin red) ya no se confunde con "no hay sesión": conserva el usuario
   y deja que el guard reintente en la siguiente navegación.
9. `registerType: 'prompt'` se conserva; el problema del chunk faltante se resuelve activando el
   service worker nuevo cuando la carga falla, no actualizando en silencio siempre.
10. El registro del service worker pasa a vivir en un módulo propio para que exista **un solo**
    registro compartido entre el aviso de actualización y el manejador de `vite:preloadError`.
11. La bandera anti-bucle de recarga se ata a un identificador de compilación, no a
    `router.isReady()`, porque la navegación inicial casi nunca es la que falla.
12. El mensaje de `beforeinstallprompt` en consola es esperado y no se toca nada por él.
13. La verificación de las causas 1 y 3 exige esperas reales (más de 2 horas de inactividad) y un
    despliegue real; en la implementación se cubrirán con pruebas que manipulen `last_activity` y
    el reloj en vez de esperar, y las comprobaciones que solo se pueden hacer en un navegador real
    quedarán anotadas como pendientes en "Estado de implementación", como en las demás historias.
