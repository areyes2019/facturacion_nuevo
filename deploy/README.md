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
    ├── htaccess-public_html       .htaccess del docroot
    ├── env.production.example     plantilla del .env real
    └── robots.txt
```

## Topología en el servidor

El código de Laravel **no vive dentro del docroot**. Si viviera, `.env`,
`storage/logs/laravel.log` y `composer.lock` serían descargables por URL.

```
~/domains/prosello.com.mx/
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

`facturacion/` es **hermano** de `public_html/`, no una ruta absoluta: el front
controller la alcanza con `__DIR__.'/../facturacion'` y funciona igual si el
dominio es el principal de la cuenta o uno adicional.

## Un solo origen

```
https://prosello.com.mx/            → el SPA
https://prosello.com.mx/api/v1/*    → Laravel
https://prosello.com.mx/sanctum/*   → Laravel (cookie CSRF)
https://prosello.com.mx/up          → Laravel (healthcheck)
```

Se descartó separar en `app.` + `api.`. La autenticación es Sanctum por cookie
de sesión, no por token Bearer, y con subdominios harían falta tres piezas que
con un solo origen sencillamente no existen: una lista de orígenes en
`config/cors.php` que mantener sincronizada, una cookie emitida en
`.prosello.com.mx` —visible para cualquier subdominio que llegue a existir— y
un `preflight` extra en cada `POST`.

El único costo es que el `.htaccess` del docroot tiene que decidir qué petición
va a dónde. Es un archivo, y está versionado.

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

---

## Instalación inicial

Una sola vez. Los pasos 1–4 son manuales; del 5 en adelante los hacen los
scripts.

### 1. hPanel

- **PHP 8.3 o superior** en *Avanzado → Configuración de PHP*.
- **SSL activo** para `prosello.com.mx` en *Seguridad → SSL*.
- **Base de datos MySQL** creada en *Bases de datos → MySQL*. Anota nombre,
  usuario y contraseña: llevan el prefijo de la cuenta y la contraseña solo se
  muestra al crearla.
- **Acceso SSH** habilitado en *Avanzado → Acceso SSH*.

### 2. `deploy/config.sh`

```bash
cp deploy/config.example.sh deploy/config.sh
```

Y pon dentro el alias de `~/.ssh/config`, las dos rutas remotas, la ruta de PHP
y la URL. Comprueba que la conexión funciona sin pedir contraseña:

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

Sube el código, instala Composer, respalda (no habrá nada que respaldar) y
migra. Después, generar la llave de la aplicación:

```bash
bash deploy/artisan.sh key:generate --force
```

### 6. Permisos de escritura

```bash
ssh prosello 'chmod -R 775 ~/domains/prosello.com.mx/facturacion/storage \
                          ~/domains/prosello.com.mx/facturacion/bootstrap/cache'
```

### 7. Primer despliegue del frontend

```bash
bash deploy/deploy-frontend.sh
```

### 8. El cron

hPanel → *Avanzado → Trabajos Cron*. Una tarea, diaria a las 03:00:

```
0 3 * * * /usr/bin/php /home/uXXXXXXXX/domains/prosello.com.mx/facturacion/artisan cotizaciones:purgar-vencidas
```

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
| `facturacion/storage/` | Logs, sesiones y caché vivos. |
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

Ambos usan `rsync --delete`, así que un archivo borrado en el repositorio
desaparece también del servidor. Las rutas excluidas quedan protegidas de ese
borrado: rsync no elimina lo que no mira.

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
cd ~/domains/prosello.com.mx/facturacion
gunzip -c ~/backups/facturacion-AAAAMMDD-HHMMSS.sql.gz | mysql -u USUARIO -p BASE
```

---

## Cuando algo no funciona

| Síntoma | Causa habitual |
|---|---|
| "Cambié el `.env` y no pasó nada" | Configuración cacheada: `bash deploy/artisan.sh config:clear` y volver a desplegar. |
| El login deja de funcionar después de un deploy de frontend | El `.htaccess` de `dist/` pisó al de producción. Vuelve a subir `deploy/hostinger/htaccess-public_html`. |
| El API devuelve HTML donde se esperaba JSON | Igual que el anterior: las reglas de `/api` no se están aplicando. |
| 500 sin explicación | `ssh prosello 'tail -50 ~/domains/prosello.com.mx/facturacion/storage/logs/laravel.log'` |
| El sitio quedó en mantenimiento | `bash deploy/artisan.sh up` |
| `composer install` aborta | Falta `--no-scripts`; ver la restricción de `proc_open`. |
