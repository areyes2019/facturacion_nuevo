#!/usr/bin/env bash
#
# Comprueba desde fuera que el sitio quedó bien publicado.
#
#     deploy/verify.sh
#
# Todas las comprobaciones se hacen con curl contra la URL pública: es lo que
# ve un usuario real, no lo que dice el servidor de sí mismo.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

FALLOS=0

# comprobar <descripción> <esperado> <obtenido>
comprobar() {
    if [ "$2" = "$3" ]; then
        ok "$1"
    else
        warn "$1  (esperaba '$2', obtuve '$3')"
        FALLOS=$((FALLOS + 1))
    fi
}

codigo()      { curl -s -o /dev/null -w '%{http_code}' "$@"; }
tipo()        { curl -s -o /dev/null -w '%{content_type}' "$@"; }
cabecera()    { curl -s -o /dev/null -D - "$@" | tr -d '\r' | sed -n "s/^[Ll]ocation: //p"; }

HOST="${SITE_URL#https://}"

say "Disponibilidad"
comprobar "GET /up responde 200"                  200 "$(codigo "$SITE_URL/up")"
comprobar "GET / responde 200"                    200 "$(codigo "$SITE_URL/")"

say "Host canónico"
comprobar "http:// redirige con 301"              301 "$(codigo "http://$HOST/")"

# No se comprueba www.<host>. Con el sistema en app.prosello.com.mx, eso sería
# www.app.prosello.com.mx: un nombre con doble prefijo que no tiene registro DNS
# ni cobertura en el certificado, y que nadie teclea jamás. La regla de www sigue
# en el .htaccess —no cuesta nada y cubre el caso genérico— pero fallaría siempre
# aquí por una razón que no es un problema. El www que sí importa es el del
# dominio raíz, y se comprueba abajo.

say "Dominio raíz"
if [ "$APEX_URL" = "$SITE_URL" ]; then
    warn "APEX_URL y SITE_URL son el mismo host: la mudanza de specs/022-subdominio-app.md"
    warn "todavía no se completó. Comprobaciones del dominio raíz OMITIDAS."
else
    APEX_HOST="${APEX_URL#https://}"

    # 302 y no 301: un 301 aquí lo cachearía el navegador durante meses, y el día
    # que exista la página de clientes sus visitantes seguirían siendo lanzados al
    # sistema sin verla. Que este número sea el correcto es parte del criterio.
    comprobar "el dominio raíz redirige con 302"  302 "$(codigo "$APEX_URL/")"
    comprobar "y apunta al sistema"               "$SITE_URL/" "$(cabecera "$APEX_URL/")"
    comprobar "www. del dominio raíz va al sistema" "$SITE_URL/" "$(cabecera "https://www.$APEX_HOST/")"

    # El service worker de apagado tiene que servirse como archivo real. Si lo
    # alcanzara la redirección, el navegador trataría la actualización como error
    # y el service worker de la PWA vieja sobreviviría sirviendo su caché.
    comprobar "sw.js del dominio raíz se sirve sin redirigir" 200 "$(codigo "$APEX_URL/sw.js")"
fi

say "Separación entre SPA y API"
comprobar "ruta profunda del SPA carga la app"    200 "$(codigo "$SITE_URL/facturas/nueva")"
API_CODIGO="$(codigo "$SITE_URL/api/v1/user")"
API_TIPO="$(tipo "$SITE_URL/api/v1/user")"
comprobar "API sin sesión devuelve 401"           401 "$API_CODIGO"
case "$API_TIPO" in
    application/json*) ok "API sin sesión responde JSON" ;;
    *) warn "API sin sesión respondió '$API_TIPO' en vez de JSON — revisa el .htaccess"
       FALLOS=$((FALLOS + 1)) ;;
esac

say "Nada del proyecto es descargable"

# Aquí NO se comprueba el código de estado. Con el fallback del SPA, una ruta
# que no existe en el docroot devuelve 200 con index.html, que es correcto y no
# filtra nada. Lo que importa es el contenido: si la respuesta trae la cadena
# delatora del archivo real, entonces el archivo sí se está sirviendo.
#
#   no_filtra <ruta> <cadena que solo aparece en el archivo real>
no_filtra() {
    local cuerpo
    cuerpo="$(curl -s --max-time 20 "$SITE_URL/$1")"
    if printf '%s' "$cuerpo" | grep -qF -- "$2"; then
        warn "/$1 ESTÁ SIRVIENDO EL ARCHIVO REAL (contiene '$2')"
        FALLOS=$((FALLOS + 1))
    else
        ok "/$1 no expone el archivo"
    fi
}

no_filtra .env                     "APP_KEY="
no_filtra composer.json            "laravel/framework"
no_filtra composer.lock            "packages-dev"
no_filtra artisan                  "Illuminate\\Foundation\\Application"
no_filtra vendor/autoload.php      "ComposerAutoloaderInit"
no_filtra storage/logs/laravel.log "production.ERROR"
no_filtra .git/config              "[remote \"origin\"]"

say "PWA"
comprobar "manifest.webmanifest disponible"       200 "$(codigo "$SITE_URL/manifest.webmanifest")"
comprobar "sw.js disponible"                      200 "$(codigo "$SITE_URL/sw.js")"

say "Módulos JavaScript (.mjs)"

# El .htaccess del docroot es el único artefacto de producción que ningún script
# despliega: se sube a mano una vez. Es, por lo tanto, el que puede quedarse
# viejo en el servidor sin que nada lo delate — y lo que se rompe cuando le falta
# el AddType de .mjs no es un error visible, es una pantalla que se queda
# pensando (ver specs/018-despliegue-hostinger.md).
#
# No se puede usar una ruta fija: los assets llevan hash de contenido en el
# nombre. Se toma el primer .mjs del build y se le pide esa URL al servidor.
MJS="$(ls "$(dirname "${BASH_SOURCE[0]}")/../frontend/dist/assets"/*.mjs 2>/dev/null | head -1)"

if [ -z "$MJS" ]; then
    warn "no hay ningún .mjs en frontend/dist/assets — corre 'npm run build' primero."
    warn "Comprobaciones de módulos OMITIDAS."
else
    MJS_CABECERAS="$(curl -s -o /dev/null -D - "$SITE_URL/assets/$(basename "$MJS")" | tr -d '\r')"
    MJS_TIPO="$(printf '%s' "$MJS_CABECERAS" | sed -n 's/^[Cc]ontent-[Tt]ype: //p')"
    MJS_CACHE="$(printf '%s' "$MJS_CABECERAS" | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"

    case "$MJS_TIPO" in
        */javascript*) ok ".mjs se sirve como JavaScript ($MJS_TIPO)" ;;
        *) warn ".mjs llega como '$MJS_TIPO' — falta 'AddType text/javascript .mjs' en el .htaccess."
           warn "  Un navegador no ejecuta un módulo con ese tipo: la lectura de constancias en PDF se cuelga."
           FALLOS=$((FALLOS + 1)) ;;
    esac

    case "$MJS_CACHE" in
        *immutable*) ok ".mjs con hash se cachea como inmutable" ;;
        *) warn ".mjs trae Cache-Control '$MJS_CACHE' — debería incluir immutable"
           FALLOS=$((FALLOS + 1)) ;;
    esac
fi

say "Cabeceras de caché"
CACHE_INDEX="$(curl -s -o /dev/null -D - "$SITE_URL/" | tr -d '\r' | sed -n 's/^[Cc]ache-[Cc]ontrol: //p')"
case "$CACHE_INDEX" in
    *no-cache*) ok "index.html se revalida (no-cache)" ;;
    *) warn "index.html trae Cache-Control '$CACHE_INDEX' — debería incluir no-cache"
       FALLOS=$((FALLOS + 1)) ;;
esac

echo
if [ "$FALLOS" -eq 0 ]; then
    printf '%sTodo correcto.%s\n\n' "$C_OK" "$C_OFF"
else
    printf '%s%d comprobación(es) fallaron.%s\n\n' "$C_ERR" "$FALLOS" "$C_OFF"
    exit 1
fi
