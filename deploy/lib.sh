#!/usr/bin/env bash
# Funciones y comprobaciones comunes a los scripts de despliegue.
# No se ejecuta directamente: los demás scripts lo cargan con `. lib.sh`.

set -euo pipefail

DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$DEPLOY_DIR/.." && pwd)"

if [ ! -f "$DEPLOY_DIR/config.sh" ]; then
    printf '\nERROR: falta deploy/config.sh\n\n' >&2
    printf 'Ese archivo tiene los datos reales del servidor y no se versiona.\n' >&2
    printf 'Créalo a partir de la plantilla y ajusta los valores:\n\n' >&2
    printf '    cp deploy/config.example.sh deploy/config.sh\n\n' >&2
    exit 1
fi

# shellcheck source=/dev/null
. "$DEPLOY_DIR/config.sh"

: "${SSH_ALIAS:?falta SSH_ALIAS en deploy/config.sh}"
: "${REMOTE_APP:?falta REMOTE_APP en deploy/config.sh}"
: "${REMOTE_DOCROOT:?falta REMOTE_DOCROOT en deploy/config.sh}"
: "${REMOTE_PHP:?falta REMOTE_PHP en deploy/config.sh}"
: "${SITE_URL:?falta SITE_URL en deploy/config.sh}"

if [ -t 1 ]; then
    C_TITLE=$'\033[1;36m'; C_OK=$'\033[0;32m'; C_WARN=$'\033[0;33m'
    C_ERR=$'\033[0;31m';   C_OFF=$'\033[0m'
else
    C_TITLE=''; C_OK=''; C_WARN=''; C_ERR=''; C_OFF=''
fi

say()  { printf '\n%s==> %s%s\n' "$C_TITLE" "$*" "$C_OFF"; }
ok()   { printf '    %sOK%s   %s\n' "$C_OK" "$C_OFF" "$*"; }
warn() { printf '    %s!!%s   %s\n' "$C_WARN" "$C_OFF" "$*"; }
die()  { printf '\n%sERROR: %s%s\n\n' "$C_ERR" "$*" "$C_OFF" >&2; exit 1; }

# Ejecuta un comando en el servidor. BatchMode evita que el script se quede
# colgado esperando una contraseña que nadie va a escribir.
remote() { ssh -o BatchMode=yes "$SSH_ALIAS" "$@"; }

# Comprueba la conexión antes de hacer cualquier cosa. Fallar aquí es barato;
# fallar a mitad de un rsync deja el servidor en un estado intermedio.
require_connection() {
    say "Comprobando conexión con $SSH_ALIAS"
    remote true >/dev/null 2>&1 \
        || die "no se pudo conectar a '$SSH_ALIAS'. Prueba a mano: ssh $SSH_ALIAS"
    ok "conectado"
}

# El servidor tiene que estar instalado. Sin .env, artisan no arranca y el
# error que da no se parece en nada a la causa.
require_installed() {
    remote "[ -f '$REMOTE_APP/.env' ]" \
        || die "no existe $REMOTE_APP/.env
       El servidor todavía no está instalado. Sigue la sección
       'Instalación inicial' de deploy/README.md antes de desplegar."
}
