#!/usr/bin/env bash
# Plantilla de configuración del despliegue.
#
# Copia este archivo a deploy/config.sh y pon los valores reales:
#
#     cp deploy/config.example.sh deploy/config.sh
#
# deploy/config.sh está en .gitignore y NUNCA se versiona: contiene la ruta real
# del servidor, que incluye el identificador de la cuenta de hosting.

# Alias de ~/.ssh/config que apunta al servidor. Define ahí HostName, Port,
# User e IdentityFile en vez de repetirlos en cada script.
SSH_ALIAS="mi-servidor"

# Carpeta del backend Laravel, FUERA del docroot.
REMOTE_APP="/home/uXXXXXXXX/domains/app.ejemplo.com/facturacion"

# Docroot del subdominio: aquí conviven el SPA compilado y el front controller.
REMOTE_DOCROOT="/home/uXXXXXXXX/domains/app.ejemplo.com/public_html"

# Ruta del binario de PHP en el servidor (`which php` por SSH).
REMOTE_PHP="/usr/bin/php"

# URL pública del sistema, sin barra final. La usa deploy/verify.sh.
SITE_URL="https://app.ejemplo.com"

# Dominio raíz, sin barra final. El sistema NO vive aquí: mientras la landing de
# specs/037-landing-prosello.md no esté publicada, redirige al sistema (ver
# specs/022-subdominio-app.md); verify.sh detecta cuál de los dos casos aplica
# mirando la respuesta real del servidor, no hace falta indicarlo aquí.
APEX_URL="https://ejemplo.com"

# Docroot del dominio raíz — mismo docroot que APEX_URL, hoy con la redirección
# de la 022 y a partir de deploy-landing.sh con la landing real (ver
# specs/037-landing-prosello.md).
REMOTE_LANDING_DOCROOT="/home/uXXXXXXXX/domains/ejemplo.com/public_html"
