#!/usr/bin/env bash
#
# Compila el SPA y lo publica en el docroot del servidor.
#
#     deploy/deploy-frontend.sh
#     deploy/deploy-frontend.sh --sin-compilar   (sube el dist/ que ya existe)
#
# El build se hace SIEMPRE en la máquina de desarrollo: el plan compartido de
# Hostinger no tiene Node.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

COMPILAR=1
for arg in "$@"; do
    case "$arg" in
        --sin-compilar) COMPILAR=0 ;;
        *) die "argumento desconocido: $arg" ;;
    esac
done

require_connection

# --- Build -------------------------------------------------------------------
if [ "$COMPILAR" = "1" ]; then
    say "Compilando el frontend"
    ( cd "$REPO_ROOT/frontend" && npm run build ) \
        || die "el build falló; no se subió nada"
    ok "build terminado"
fi

DIST="$REPO_ROOT/frontend/dist"
[ -f "$DIST/index.html" ] || die "no existe frontend/dist/index.html — corre el build primero"

# --- Publicación -------------------------------------------------------------
# Las tres exclusiones son la parte importante de este script y hacen dos cosas
# a la vez, porque rsync no borra en destino lo que tiene excluido:
#
#   .htaccess   Vite copia el suyo (cabeceras de caché de la PWA) a dist/. Si
#               llegara al docroot pisaría el .htaccess de producción, que es
#               el que decide qué petición va al SPA y cuál a Laravel: el
#               login dejaría de funcionar sin ningún error que lo explique.
#               El de producción ya incorpora esas cabeceras de caché.
#   index.php   Front controller de Laravel. No viene del build y no se toca.
#   robots.txt  Se sube una sola vez en la instalación inicial.
say "Publicando dist/ en el docroot"
rsync -rlptz --delete --human-readable \
    -e "ssh -o BatchMode=yes" \
    --exclude='.htaccess' \
    --exclude='index.php' \
    --exclude='robots.txt' \
    "$DIST/" "$SSH_ALIAS:$REMOTE_DOCROOT/"
ok "SPA publicado"

# El .htaccess y el index.php de producción tienen que seguir ahí. Si faltan,
# el sitio responde 404 o descarga el PHP en crudo, y más vale saberlo ahora.
say "Comprobando los archivos de producción del docroot"
for archivo in .htaccess index.php; do
    if remote "[ -f '$REMOTE_DOCROOT/$archivo' ]"; then
        ok "$archivo presente"
    else
        warn "FALTA $REMOTE_DOCROOT/$archivo — súbelo desde deploy/hostinger/ (ver README)"
    fi
done

say "Frontend desplegado"
printf '    Verifica con:  deploy/verify.sh\n\n'
