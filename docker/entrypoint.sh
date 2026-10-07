#!/bin/sh
# O volume /data nasce vazio: acerta o dono e sobe o Apache.
set -e
DIR="${CIPA_DATA:-/data}"
mkdir -p "$DIR"
chown -R www-data:www-data "$DIR"
chmod 750 "$DIR"
if [ -z "$CIPA_ADMIN_SENHA" ]; then
    echo "[urna-cipa] AVISO: CIPA_ADMIN_SENHA não definida — a comissão não consegue entrar até definir." >&2
fi
exec "$@"