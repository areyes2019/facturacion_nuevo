# Despliegue en Hostinger

Todo se ejecuta **desde la máquina de desarrollo**, con Git Bash. Nada de esto
necesita abrir una sesión SSH a mano ni entrar al File Manager de hPanel.

```
deploy/
├── config.sh                      datos reales del servidor  (NO versionado)
├── config.example.sh              su plantilla               (versionado)
├── lib.sh                         funciones comunes
├── artisan.sh                     corre artisan en producción
├── deploy-backend.sh              publica el backend
├── deploy-frontend.sh             publica el SPA
├── verify.sh                      comprueba el sitio publicado
└── hostinger/                     archivos que se suben UNA SOLA VEZ
    ├── index.php                  front controller de producción
    ├── htaccess-public_html       .htaccess del docroot del sistema
    ├── htaccess-apex              .htaccess del docroot del dominio raíz
    ├── sw-apex.js                 service worker de apagado del dominio raíz
    ├── env.production.example     plantilla del .env real
    └── robots.txt
```

## Topología en el servidor

El sistema vive en el subdominio **`app.prosello.com.mx`**. El dominio raíz
quedó libre para la futura página web de clientes (ver
`specs/022-subdominio-app.md`).

El código de Laravel **no vive dentro del docroot**. Si viviera, `.env`,
`storage/logs/laravel.log` y `composer.lock` serían descargables por URL.

```
~/domains/app.prosello.com.mx/
├── facturacion/                ← fuera del docroot, inaccesible por web
│   ├── app/ bootstrap/ config/ database/ routes/ storage/ vendor/
│   ├── artisan
│   └── .env                    ← credenciales reales
│
└── public_html/                ← docroot
    ├── .htaccess               ← de deploy/hostinger/
    ├── index.php               ← de deploy/hostinger/
    ├── robots.txt              ← de deploy/hostinger/
    ├── index.html              ← el SPA, desde frontend/dist/
    ├── assets/                 ← chunks con hash de Vite
    ├── sw.js  registerSW.js  manifest.webmanifest
    └── favicon.svg  icons.svg  apple-touch-icon.png  pwa-*.png
```

Y al lado, sin nada del sistema:

```
~/domains/prosello.com.mx/
└── public_html/                ← docroot del dominio raíz
    ├── .htaccess               ← de deploy/hostinger/htaccess-apex
    └── sw.js                   ← de deploy/hostinger/sw-apex.js
```

`facturacion/` es **hermano** de `public_html/`, no una ruta absoluta: el front
controller la alcanza con `__DIR__.'/../facturacion'` y funciona igual en
cualquier dominio de la cuenta. Esa ruta relativa es lo que hizo que la mudanza
al subdominio no obligara a tocar el front controller.

**El subdominio se creó como sitio web independiente** (hPanel → *Añadir sitio
web* → *Sitio web PHP/HTML personalizado*), no desde la pantalla de
*Subdominios*: esa otra fuerza la casilla «Carpeta personalizada» y deja el
docroot **dentro** del `public_html/` del dominio raíz, con tres consecuencias
malas —el sistema queda accesible también como `prosello.com.mx/app`, los
`.htaccess` del dominio raíz se aplican encima de las peticiones del subdominio,
y el código de Laravel se queda sin lugar fuera del docroot—.

## Un solo origen

```
https://app.prosello.com.mx/            → el SPA
https://app.prosello.com.mx/api/v1/*    → Laravel
https://app.prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://app.prosello.com.mx/up          → Laravel (healthcheck)
```

Se descartó separar el API en `api.prosello.com.mx`. La autenticación es Sanctum
por cookie de sesión, no por token Bearer, y con el API en otro host harían falta
tres piezas que con un solo origen sencillamente no existen: una lista de
orígenes en `config/cors.php` que mantener sincronizada, una cookie emitida en
`.prosello.com.mx` —visible para cualquier host del dominio, incluida la futura
página de clientes— y un `preflight` extra en cada `POST`.

El único costo es que el `.htaccess` del docroot tiene que decidir qué petición
va a dónde. Es un archivo, y está versionado.

## El dominio raíz

`prosello.com.mx` no tiene ni un archivo del sistema. Su docroot contiene dos
cosas, y las dos se suben a mano una sola vez:

| Archivo | De dónde sale | Para qué |
|---|---|---|
| `.htaccess` | `deploy/hostinger/htaccess-apex` | Redirige todo al sistema con **302** |
| `sw.js` | `deploy/hostinger/sw-apex.js` | Apaga el service worker de la PWA vieja |

**Por qué 302 y no 301.** Un 301 es permanente y los navegadores lo cachean
durante meses. El día que exista la página de clientes y se quite la regla, todo
el que hubiera entrado antes seguiría siendo lanzado al sistema sin verla nunca,
y limpiarlo exigiría que cada usuario vaciara su caché. Con 302 el navegador
vuelve a preguntar cada vez.

**Por qué hay un `sw.js` ahí.** Quien tuviera la PWA instalada desde el dominio
raíz conserva un service worker registrado en ese origen, que sirve la interfaz
desde su propia caché: la redirección no lo alcanza, porque responde antes de que
la petición salga a la red. Y no se apaga solo — el navegador comprueba las
actualizaciones pidiendo `/sw.js`, y **una redirección en esa petición se trata
como error**, así que el service worker viejo sobreviviría intacto. Por eso el
`.htaccess` excluye `sw.js` de la redirección y ahí hay un archivo real que borra
las cachés, se desregistra y recarga la ventana.

**El día que se publique la landing** (specs/037-landing-prosello.md,
`deploy/deploy-landing.sh`), ese sitio reemplaza el `.htaccess` de redirección.
`verify.sh` detecta el cambio solo —mirando si `GET /` del dominio raíz responde
302 (todavía redirigiendo) o 200 (la landing ya está ahí)— y comprueba lo que
corresponda en cada caso, sin que haga falta tocar ninguna bandera. El `sw.js`
conviene conservarlo mientras queden instalaciones antiguas de la PWA dando
vueltas: no estorba a la landing, que no lo usa.

---

## La landing (`prosello.com.mx`)

Publicar `landing/` en el dominio raíz (specs/037-landing-prosello.md) requiere
que la mudanza de la sección anterior ya haya terminado en el servidor —se
escribe sobre el mismo docroot que hoy tiene la redirección—.

**Primera vez:**

1. `REMOTE_LANDING_DOCROOT` en `deploy/config.sh` (mismo docroot que `APEX_URL`,
   ver `config.example.sh`).
2. En el backend, `.env` gana `LANDING_URL` (el origen de la landing, para que
   `config/cors.php` acepte su `POST /api/v1/contacto`) y
   `LANDING_CONTACTO_EMAIL` (a dónde llega el formulario). Sin
   `bash deploy/artisan.sh config:cache` después de tocar `.env`, el cambio no
   se ve.
3. Subir el `.htaccess`:
   ```bash
   scp deploy/hostinger/htaccess-landing \
       prosello:$REMOTE_LANDING_DOCROOT/.htaccess
   ```
   Este paso reemplaza al `.htaccess` de redirección de la sección anterior —a
   partir de aquí el dominio raíz deja de redirigir y sirve la landing.
4. `bash deploy/deploy-landing.sh` compila `landing/` y sube `dist/`.
5. `bash deploy/verify.sh` — el bloque "Dominio raíz" pasa a comprobar la
   landing en vez de la redirección (ver arriba).

**Cambios normales**, ya con lo anterior hecho: `bash deploy/deploy-landing.sh`
compila y sube; no toca `.htaccess` ni ningún archivo del backend.

**Qué no hace este script:** no toca `REMOTE_APP` ni `REMOTE_DOCROOT` (el
sistema, en `app.prosello.com.mx`), no borra el `sw.js` de apagado de la PWA
vieja, y no reinicia nada en el backend — el formulario de contacto vive del
lado de `deploy-backend.sh`, como cualquier otra ruta del API.

---

## Restricciones del servidor

Verificadas en `prosello` el 2026-08-11:

| | |
|---|---|
| PHP (web y consola) | 8.4 |
| Extensiones | `gd`, `pdo_mysql`, `mbstring`, `curl`, `dom`, `fileinfo`, `iconv`, `zip`, `openssl` |
| Herramientas | `git`, `rsync`, `composer`, `mysqldump` |
| Node | **no hay** — el build del SPA se hace siempre en local |

Y una restricción que condiciona dos pasos. PHP tiene deshabilitadas estas
funciones:

```
system, exec, shell_exec, passthru, proc_open, popen, symlink, link, ...
```

Consecuencias:

- **`composer install` corre con `--no-scripts`.** El script `post-autoload-dump`
  de Laravel invoca `artisan package:discover` a través de
  `Symfony\Component\Process`, que sin `proc_open` ni siquiera se construye, y
  aborta la instalación entera. El descubrimiento se hace después, llamando a
  `php artisan package:discover` directamente. `deploy-backend.sh` ya lo hace.

- **`schedule:run` queda descartado.** El planificador de Laravel lanza cada
  tarea con `Symfony\Component\Process`. Y no fallaría de forma visible:
  mientras ninguna tarea esté vencida no construye ningún `Process`, así que el
  cron se vería sano y las tareas simplemente no correrían. Por eso el comando
  se invoca directo desde el cron (ver más abajo).

- **`php artisan storage:link` no funciona** (necesita `symlink`). No hace
  falta: `FILESYSTEM_DISK` es `local` y no se sirve ningún archivo desde
  `storage/`.

Y una más, sin relación con `proc_open`: **no hay `crontab` en la shell**. La
tarea programada se administra únicamente desde hPanel, así que su línea —y
sobre todo la ruta que contiene— es un paso manual que ningún script puede
cambiar por ti.

---

## Instalación inicial

Una sola vez. Los pasos 1–4 son manuales; del 5 en adelante los hacen los
scripts.

### 1. hPanel

- **PHP 8.3 o superior** en *Avanzado → Configuración de PHP*.
- **El subdominio creado como sitio web independiente**: *Añadir sitio web →
  Sitio web PHP/HTML personalizado*, con el nombre completo
  `app.prosello.com.mx`. No desde *Subdominios* (ver la topología, arriba).
- **SSL activo** para `app.prosello.com.mx` en *Seguridad → SSL*.
- **Base de datos MySQL** creada en *Bases de datos → MySQL*. Anota nombre,
  usuario y contraseña: llevan el prefijo de la cuenta y la contraseña solo se
  muestra al crearla.
- **Acceso SSH** habilitado en *Avanzado → Acceso SSH*.

### 2. `deploy/config.sh`

```bash
cp deploy/config.example.sh deploy/config.sh
```

Y pon dentro el alias de `~/.ssh/config`, las dos rutas remotas, la ruta de PHP,
la URL del sistema y la del dominio raíz (`APEX_URL`). Comprueba que la conexión
funciona sin pedir contraseña:

```bash
ssh -o BatchMode=yes prosello 'echo ok'
```

### 3. El docroot, vacío

Hostinger deja un `default.php` de bienvenida que hay que quitar.

> **Nunca escribas `~` sin comillas en la parte remota de un `scp` o un `ssh`.**
> Git Bash lo expande **en tu máquina** antes de mandarlo, y el comando acaba
> apuntando a `C:\Users\<tú>\domains\...`, que no existe. Por eso todos los
> comandos de aquí cargan `deploy/config.sh` y usan sus rutas absolutas.

```bash
. deploy/config.sh
ssh "$SSH_ALIAS" "rm -f '$REMOTE_DOCROOT/default.php'"
```

### 4. Los cuatro archivos que se suben una sola vez

```bash
. deploy/config.sh
scp deploy/hostinger/index.php            "$SSH_ALIAS:$REMOTE_DOCROOT/index.php"
scp deploy/hostinger/robots.txt           "$SSH_ALIAS:$REMOTE_DOCROOT/robots.txt"
scp deploy/hostinger/htaccess-public_html "$SSH_ALIAS:$REMOTE_DOCROOT/.htaccess"
```

Ojo con el tercero: cambia de nombre al llegar (`.htaccess`, con punto).

Y los dos del **dominio raíz**, que van a su propio docroot y también cambian de
nombre. `APEX_DOCROOT` no está en `config.sh` porque los scripts nunca escriben
ahí; se pone a mano una vez:

```bash
APEX_DOCROOT="/home/u648715341/domains/prosello.com.mx/public_html"
scp deploy/hostinger/htaccess-apex "$SSH_ALIAS:$APEX_DOCROOT/.htaccess"
scp deploy/hostinger/sw-apex.js    "$SSH_ALIAS:$APEX_DOCROOT/sw.js"
```

Y el `.env`, que es el único con secretos. Se sube la plantilla y se rellena
**en el servidor**, para que los valores reales no pasen nunca por un archivo
local:

```bash
. deploy/config.sh
scp deploy/hostinger/env.production.example "$SSH_ALIAS:$REMOTE_APP/.env"
ssh "$SSH_ALIAS" -t "nano '$REMOTE_APP/.env'"
```

Rellena los `<...>` con los valores reales de hPanel.

### 5. Primer despliegue del backend

```bash
bash deploy/deploy-backend.sh
```

Sube el código, instala Composer, sincroniza los catálogos del SAT (ver abajo;
si `backend/storage/app/sat-catalogos.sqlite` no existe todavía, el script se
detiene y te dice qué correr), respalda (no habrá nada que respaldar) y migra.
Después, generar la llave de la aplicación:

```bash
bash deploy/artisan.sh key:generate --force
```

### 6. Permisos de escritura

```bash
ssh prosello 'chmod -R 775 ~/domains/app.prosello.com.mx/facturacion/storage \
                          ~/domains/app.prosello.com.mx/facturacion/bootstrap/cache'
```

### 7. Primer despliegue del frontend

```bash
bash deploy/deploy-frontend.sh
```

### 8. El cron

hPanel → *Avanzado → Trabajos Cron*. Una tarea, diaria a las 03:00:

```
0 3 * * * /usr/bin/php /home/uXXXXXXXX/domains/app.prosello.com.mx/facturacion/artisan cotizaciones:purgar-vencidas
```

> **La ruta va al subdominio.** Es un paso manual: el servidor no tiene
> `crontab` en la shell, así que esta línea solo se edita desde hPanel. Y tiene
> que quedar **una sola**: dos líneas apuntando a dos copias del sistema
> correrían la purga dos veces contra la misma base.

> **Advertencia.** Se invoca el comando directamente, no `schedule:run` (ver
> arriba por qué). La contrapartida es que **toda tarea programada que se
> agregue en el futuro a `routes/console.php` no se ejecutará** hasta que se le
> agregue aquí su propia línea de cron. Hoy la única es
> `cotizaciones:purgar-vencidas`.

### 9. Verificar

```bash
bash deploy/verify.sh
```

---

## Desplegar un cambio

**Backend** (código PHP, migraciones, configuración):

```bash
bash deploy/deploy-backend.sh
bash deploy/deploy-backend.sh --sin-migrar    # si no hay migraciones nuevas
```

**Frontend** (pantallas, estilos, assets):

```bash
bash deploy/deploy-frontend.sh
bash deploy/deploy-frontend.sh --sin-compilar # reusa el dist/ que ya existe
```

**Los dos**: corre los dos scripts, en cualquier orden.

**Verificar siempre al final**:

```bash
bash deploy/verify.sh
```

### Correr artisan en producción

```bash
bash deploy/artisan.sh migrate --force
bash deploy/artisan.sh migrate:status
bash deploy/artisan.sh config:clear
bash deploy/artisan.sh cotizaciones:purgar-vencidas
```

`migrate:fresh`, `migrate:reset` y `db:wipe` están **bloqueados** en el script:
vacían la base entera. Si alguna vez hicieran falta de verdad, se hacen por SSH
a mano y con un respaldo delante.

---

## Qué toca y qué no toca cada script

`deploy-backend.sh` **nunca** escribe en:

| Ruta | Por qué |
|---|---|
| `facturacion/.env` | Credenciales reales. Se edita a mano en el servidor. |
| `facturacion/storage/` | Logs, sesiones y caché vivos. **Una excepción**: `storage/app/sat-catalogos.sqlite`, que sí se sincroniza (ver abajo). |
| `facturacion/bootstrap/cache/` | Caché compilada; se regenera sola. |
| `facturacion/vendor/` | Lo instala Composer allá, no se sube. |
| `public_html/` | Territorio del frontend. |

`deploy-frontend.sh` **nunca** escribe en:

| Ruta | Por qué |
|---|---|
| `public_html/index.php` | Front controller de Laravel; no viene del build. |
| `public_html/.htaccess` | El de producción. Vite genera uno propio en `dist/` que lo pisaría y rompería el enrutado hacia Laravel. |
| `public_html/robots.txt` | Se sube una sola vez. |
| `facturacion/` | Territorio del backend. |

`deploy-landing.sh` **nunca** escribe en:

| Ruta | Por qué |
|---|---|
| `.htaccess` del docroot de la landing | Se sube a mano una sola vez (ver "La landing" arriba). |
| `app.prosello.com.mx/` (`REMOTE_APP`/`REMOTE_DOCROOT`) | Es otro dominio; territorio del sistema. |

Los tres usan `rsync --delete`, así que un archivo borrado en el repositorio
desaparece también del servidor. Las rutas excluidas quedan protegidas de ese
borrado: rsync no elimina lo que no mira.

---

## Los catálogos del SAT

Los seis catálogos que usa el sistema —régimen fiscal, código postal, clave de
producto/servicio, clave de unidad, uso de CFDI y forma de pago— **no están en
MySQL**. Viven en una base SQLite de ~13 MB:

```
backend/storage/app/sat-catalogos.sqlite
```

Ese archivo no está en git (son binarios regenerables, no código) y cae dentro
de `storage/`, que el despliegue excluye por regla general. Es la única
excepción a esa regla: `deploy-backend.sh` lo sincroniza en cada despliegue,
pero **solo si cambió** —compara `md5sum` antes de mover un byte—, lo sube
comprimido (~3 MB en tránsito), lo escribe con nombre temporal y recién
entonces lo mueve sobre el definitivo, y verifica el checksum del resultado.

El nombre temporal importa. Una transferencia interrumpida sobre el archivo
definitivo deja una base truncada, y SQLite abre una base truncada **sin dar
error**: los catálogos simplemente responden vacío. Desde la pantalla se ve
igual que una búsqueda sin resultados, y no hay nada en el log.

Para actualizar los catálogos cuando el SAT publica una versión nueva:

```bash
cd backend && php artisan catalogos-sat:actualizar
bash deploy/deploy-backend.sh --sin-migrar
```

El comando descarga el recurso de `phpcfdi/resources-sat-catalogs` y reconstruye
la base **en local**; el despliegue la copia al servidor. No se corre en
producción a propósito: haría que un despliegue dependa de que GitHub responda,
y dejaría al servidor con una versión de los catálogos que nunca se probó aquí.

---

## Respaldos

`deploy-backend.sh` guarda un volcado comprimido en `~/backups/` **antes** de
cada migración, y conserva los 10 más recientes.

```bash
ssh prosello 'ls -lht ~/backups/'
```

Para restaurar uno:

```bash
ssh prosello
cd ~/domains/app.prosello.com.mx/facturacion
gunzip -c ~/backups/facturacion-AAAAMMDD-HHMMSS.sql.gz | mysql -u USUARIO -p BASE
```

---

## Cuando algo no funciona

| Síntoma | Causa habitual |
|---|---|
| "Cambié el `.env` y no pasó nada" | Configuración cacheada: `bash deploy/artisan.sh config:clear` y volver a desplegar. |
| El login deja de funcionar después de un deploy de frontend | El `.htaccess` de `dist/` pisó al de producción. Vuelve a subir `deploy/hostinger/htaccess-public_html`. |
| El API devuelve HTML donde se esperaba JSON | Igual que el anterior: las reglas de `/api` no se están aplicando. |
| 500 sin explicación | `ssh prosello 'tail -50 ~/domains/app.prosello.com.mx/facturacion/storage/logs/laravel.log'` |
| Las claves de producto/servicio o de unidad no devuelven resultados, o los `<select>` de régimen fiscal y forma de pago salen vacíos | La base de catálogos SAT del servidor falta, está vacía o está truncada. `bash deploy/deploy-backend.sh --sin-migrar` la vuelve a subir. Compruébalo con `ssh prosello 'ls -l ~/domains/app.prosello.com.mx/facturacion/storage/app/sat-catalogos.sqlite'`: si pesa 0, es esto. |
| Un usuario dice que la aplicación instalada abre pero no carga datos | Tiene la PWA instalada del origen viejo (`prosello.com.mx`), sirviéndose de su caché. Se apaga sola al abrirla con red gracias al `sw.js` del dominio raíz; si no, que la desinstale y la reinstale desde `app.prosello.com.mx`. |
| `verify.sh` falla en las comprobaciones del dominio raíz | O falta el `.htaccess` de `deploy/hostinger/htaccess-apex` en su docroot, o ya se publicó la página de clientes y hay que actualizar el verificador. |
| El sitio quedó en mantenimiento | `bash deploy/artisan.sh up` |
| `composer install` aborta | Falta `--no-scripts`; ver la restricción de `proc_open`. |
