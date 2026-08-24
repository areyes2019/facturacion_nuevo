#!/usr/bin/env bash
#
# Compila la landing pública (specs/037-landing-prosello.md) y la publica en el
# docroot del dominio raíz.
#
#     deploy/deploy-landing.sh
#     deploy/deploy-landing.sh --sin-compilar   (sube el dist/ que ya existe)
#
# Requiere que la mudanza de specs/022-subdominio-app.md ya haya terminado en
# el servidor: este script escribe sobre el mismo docroot que hoy sirve la
# redirección temporal a app.prosello.com.mx.

. "$(dirname "${BASH_SOURCE[0]}")/lib.sh"

COMPILAR=1
for arg in "$@"; do
    case "$arg" in
        --sin-compilar) COMPILAR=0 ;;
        *) die "argumento desconocido: $arg" ;;
    esac
done

trap limpiar_temporales EXIT

require_connection

# --- Build -------------------------------------------------------------------
if [ "$COMPILAR" = "1" ]; then
    say "Compilando la landing"
    ( cd "$REPO_ROOT/landing" && npm run build ) \
        || die "el build falló; no se subió nada"
    ok "build terminado"
fi

DIST="$REPO_ROOT/landing/dist"
[ -f "$DIST/index.html" ] || die "no existe landing/dist/index.html — corre el build primero"

# --- Publicación ---------------------------------------------------------------
# Sin exclusiones: a diferencia del docroot del sistema, aquí no convive nada
# ajeno al build (ni front controller, ni .htaccess de producción propio) — el
# .htaccess de la landing se sube aparte, una sola vez, igual que en 018/022.
say "Publicando dist/ en el docroot de la landing"
subir_paquete "$DIST" "$REMOTE_LANDING_DOCROOT" --exclude='./.htaccess'
ok "landing publicada"

borrar_sobrantes "$REMOTE_LANDING_DOCROOT" "-name .htaccess -prune -o"

say "Comprobando el .htaccess de producción del docroot"
if remote "[ -f '$REMOTE_LANDING_DOCROOT/.htaccess' ]"; then
    ok ".htaccess presente"
else
    warn "FALTA $REMOTE_LANDING_DOCROOT/.htaccess — súbelo desde deploy/hostinger/htaccess-landing (ver README)"
fi

say "Landing desplegada"
printf '    Verifica con:  deploy/verify.sh\n\n'
