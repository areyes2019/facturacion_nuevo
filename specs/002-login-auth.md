# Spec: Login + recuperación de contraseña

## Historia de usuario

Como usuario, quiero iniciar sesión desde un formulario de login (correo + contraseña) que me
lleve a mi página de inicio dentro del panel de control (por ahora una página en blanco con el
texto "pagina de inicio"), y quiero poder recuperar mi contraseña vía un link "olvidé mi
contraseña" que dispare un correo electrónico con instrucciones para restablecerla.

## Objetivo / Alcance

Implementar el flujo completo de autenticación (login, logout, recuperación de contraseña) sobre
la base ya existente de Laravel API + Vue 3 SPA + Sanctum stateful (ver
[001-inicio-proyecto.md](001-inicio-proyecto.md)), usando Laravel Breeze (stack `--api`) como
scaffolding de partida. No incluye registro público de usuarios ni verificación de email.

## Backend (Laravel)

- **Laravel Breeze** instalado con el stack `--api` (`php artisan breeze:install api`), que genera
  controladores de auth listos para consumirse desde una SPA vía Sanctum.
- Rutas de auth expuestas (ajustadas al prefijo `/api/v1`):
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/logout`
  - `POST /api/v1/auth/forgot-password`
  - `POST /api/v1/auth/reset-password`
  - `GET /api/v1/user` (protegida con `auth:sanctum`, para verificar sesión activa)
- Autenticación vía Sanctum SPA stateful (cookies), reutilizando `SANCTUM_STATEFUL_DOMAINS` y CORS
  ya configurados.
- **Recuperación de contraseña**: flujo nativo de Laravel (`Password::sendResetLink` /
  `Password::reset`) usando la tabla `password_reset_tokens` ya presente en las migraciones base.
- **"Recordarme"**: checkbox en el login que, al marcarse, extiende la duración de la cookie de
  sesión (ej. 30 días) en vez de expirar con `SESSION_LIFETIME` (120 min) por defecto.
- **Verificación de email**: no se implementa en esta historia (no aplica sin un flujo de
  registro público todavía).
- **Usuario de prueba**: se agrega un seeder con un usuario de ejemplo (correo/contraseña
  conocidos) para poder probar el login sin tener registro.
- **Rate limiting**: throttle básico (ej. 5 intentos/minuto) en `login` y `forgot-password`.
- **Validaciones**: `email` requerido y con formato válido; `password` requerido, mínimo 8
  caracteres.
- **Mensajes de error de login**: genéricos ("credenciales incorrectas"), sin indicar qué campo
  fue el incorrecto.

## Correo (desarrollo)

- Se usa **Mailpit** como servidor SMTP falso para capturar el correo de recuperación de
  contraseña en una interfaz web local, en vez de enviarlo realmente o solo loguearlo.
- Si Laragon no trae Mailpit instalado, se instala/levanta como parte de la implementación y se
  documentan los datos de conexión (host/puerto) en `.env`.

## Frontend (Vue 3)

- **`/login`** (pública): formulario con input de correo, input de contraseña, checkbox
  "recordarme", link "olvidé mi contraseña", y redirige a `/dashboard` si el login es exitoso o si
  ya existe una sesión activa.
- **`/forgot-password`**: formulario con input de correo para solicitar el link de recuperación.
- **`/reset-password`**: formulario de nueva contraseña, recibe `token` y `email` por query string
  (los que trae el link del correo).
- **`/dashboard`** (protegida): página en blanco con el texto "pagina de inicio", más un botón
  simple de "Cerrar sesión" que llama a `POST /api/v1/auth/logout` y redirige a `/login`.
- **Guard de rutas**: Vue Router redirige a `/login` si no hay sesión activa al intentar acceder a
  `/dashboard`, y valida la sesión contra `GET /api/v1/user` al cargar la SPA.
- **Estado de auth**: manejado en un store de Pinia (`stores/auth.ts`) — usuario actual, estado de
  carga, errores.
- Sin librería de estilos (no Tailwind/Vuetify) todavía; formularios con HTML mínimo, sin foco en
  diseño visual.

## Fuera de alcance

- Registro público de usuarios.
- Verificación de email.
- Recuperación de contraseña vía SMS u otro canal distinto a correo.
- Roles/permisos dentro del panel de control.
- Diseño visual/UI pulido del login y el dashboard.
- Contenido real del panel de control más allá del texto "pagina de inicio".

## Estado de implementación

Implementada el 2026-07-30.

- **Breeze `--api` no pone las rutas en `routes/api.php`**: por defecto las registra en
  `routes/web.php` (vía `require __DIR__.'/auth.php'`), bajo el guard `web` sin prefijo `/api`.
  Para cumplir con `/api/v1/auth/*` se movieron a `routes/api.php` dentro de
  `Route::prefix('auth')->group(...)`, apoyándose en `EnsureFrontendRequestsAreStateful` (ya
  prependeada al grupo `api` desde [001-inicio-proyecto.md](001-inicio-proyecto.md)) para que la
  sesión/CSRF stateful sigan funcionando igual que en el guard `web`. `routes/web.php` quedó igual
  que en la spec 001 (sin rutas).
- **Rate limiting de login**: `LoginRequest` de Breeze ya trae un lockout propio de 5 intentos por
  email+IP con decaimiento de 60s (equivalente a "5/minuto"); se dejó tal cual. Se agregó
  `throttle:5,1` explícito en las rutas de `login` y `forgot-password` como capa adicional a nivel
  de IP.
- **Recuperación de contraseña**: `Password::sendResetLink`/`Password::reset` nativos de Laravel
  (generados por Breeze), sin cambios de lógica. Se sobreescribió
  `ResetPassword::createUrlUsing()` en `AppServiceProvider` para apuntar a
  `{FRONTEND_URL}/reset-password?token=...&email=...` en vez del path por defecto de Breeze
  (`/password-reset/{token}?email=...`).
- **Recordarme**: implementado como middleware propio
  (`app/Http/Middleware/ExtendSessionLifetimeWhenRemembered.php`), prependeado al grupo `api`
  *antes* de `EnsureFrontendRequestsAreStateful`, que sobreescribe `session.lifetime` a 30 días
  antes de que Sanctum arranque la sesión. Se quitó el segundo argumento de `Auth::attempt()` en
  `LoginRequest` para no usar además la cookie `remember_token` clásica (redundante con el enfoque
  elegido).
- **Código Breeze fuera de alcance eliminado**: `RegisteredUserController`,
  `EmailVerificationNotificationController`, `VerifyEmailController`,
  `App\Http\Middleware\EnsureEmailIsVerified`, y sus tests (`RegistrationTest`,
  `EmailVerificationTest`), junto con las rutas `/register`, `/verify-email/*` y
  `/email/verification-notification`.
- **Mailpit**: ya estaba disponible en este Laragon (`c:\laragon\bin\mailpit`); solo se configuró
  `.env` (`MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=1025`, puertos por defecto de
  Mailpit) y se agregó `config('app.frontend_url')` (faltaba en `config/app.php`).
- **Gotcha de testing (no de producción)**: los tests de Pest que viven bajo el grupo `api`
  necesitan un header `Origin` que matchee `SANCTUM_STATEFUL_DOMAINS` para que
  `EnsureFrontendRequestsAreStateful` arranque sesión (se agregó por defecto en
  `tests/TestCase::setUp()`). Además, dentro de un mismo test method, encadenar login→logout→
  chequeo de sesión requiere `$this->app->forgetInstance('auth'|'session'|'session.store')` entre
  llamadas, porque el `AuthManager`/`SessionManager` quedan cacheados como singletons durante todo
  el test (algo que no ocurre en producción real, donde cada request HTTP es un proceso nuevo — se
  verificó manualmente con `curl` que el login/logout real funciona sin este workaround).
- Verificado end-to-end con un navegador real (Playwright): los 8 criterios de aceptación pasaron,
  incluyendo la extensión de la cookie de sesión (`Max-Age` 7200 sin "recordarme" → 2592000 con
  "recordarme" marcado) y el correo de recuperación capturado en Mailpit con el link correcto.

## Criterios de aceptación

1. Un usuario con credenciales válidas (creadas por el seeder) puede loguearse desde `/login` y es
   redirigido a `/dashboard`, donde ve el texto "pagina de inicio".
2. Un usuario con credenciales inválidas ve un mensaje de error genérico y permanece en `/login`.
3. Intentar acceder a `/dashboard` sin sesión activa redirige a `/login`.
4. Marcar "recordarme" extiende la duración de la cookie de sesión más allá de los 120 minutos por
   defecto.
5. El link "olvidé mi contraseña" lleva a `/forgot-password`; al enviar un correo válido, el email
   de recuperación aparece capturado en Mailpit.
6. Abrir el link del correo de recuperación lleva a `/reset-password` con `token`/`email`
   precargados; al enviar una nueva contraseña válida, el usuario puede loguearse con ella.
7. El botón "Cerrar sesión" en `/dashboard` invalida la sesión y redirige a `/login`; intentar
   volver a `/dashboard` sin loguearse de nuevo redirige otra vez a `/login`.
8. Pint y ESLint/Prettier corren sin errores sobre el código nuevo.

## Supuestos asumidos (registro completo)

1. **(Redefinido)** Se usa **Laravel Breeze** (stack `--api`) como scaffolding de auth, en lugar
   de construir todo manualmente o usar Fortify/Jetstream.
2. Mecanismo de auth: Sanctum SPA stateful (cookies), no tokens Bearer.
3. Rutas backend agrupadas bajo `/api/v1/auth/*`.
4. Endpoint `GET /api/v1/user` protegido con `auth:sanctum` para verificar sesión activa al
   recargar la SPA.
5. Se crea un seeder con un usuario de prueba para poder probar el login.
6. Ruta frontend del panel: `/dashboard`, protegida por guard de Vue Router.
7. Ruta frontend de login: `/login`, pública, redirige a `/dashboard` si ya hay sesión.
8. Reset de contraseña con el sistema nativo de Laravel (`Password::sendResetLink` /
   `Password::reset`) y la tabla `password_reset_tokens` ya existente.
9. Pantalla `/reset-password` recibe `token` y `email` por query string.
10. **(Redefinido)** Envío de correo de recuperación vía **Mailpit** (SMTP falso con UI web), no
    vía driver `log` ni SMTP real.
11. Validaciones: email formato válido y requerido; password requerido, mínimo 8 caracteres.
12. Rate limiting básico (5 intentos/minuto) en login y forgot-password.
13. Mensajes de error de login genéricos, sin indicar campo específico.
14. **(Redefinido)** Verificación de email: no aplica en esta historia, se pospone hasta que exista
    un flujo de registro público.
15. **(Redefinido)** Checkbox "recordarme" que extiende la duración de la cookie de sesión (ej. 30
    días) cuando está marcado.
16. **(Redefinido)** Se agrega un botón/link "Cerrar sesión" visible en la página de inicio del
    panel, además del endpoint backend.
17. Diseño/UI sin librería de estilos todavía; formularios con HTML mínimo.
18. Estado de sesión en frontend manejado en un store de Pinia (`stores/auth.ts`).
19. **(Aclarado antes de implementar)** Mecanismo de "recordarme": se sobreescribe
    dinámicamente `session.lifetime` a 30 días antes de crear la sesión cuando el checkbox está
    marcado, en vez de usar la cookie `remember_token` clásica de Laravel — así la cookie de
    sesión misma dura 30 días, tal como describe la historia de usuario.
20. **(Aclarado antes de implementar)** El código que Breeze `--api` genera para registro
    público y verificación de email (fuera de alcance de esta spec) se elimina del repo en vez
    de dejarse presente sin rutear.
21. **(Aclarado antes de implementar)** Usuario de prueba del seeder: `test@example.com` /
    `password`.
