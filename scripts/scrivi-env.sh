#!/bin/sh
# Scrive su stdout il .env di un ambiente a partire dalle variabili d'ambiente (i segreti di GitHub).
# Uso: sh scripts/scrivi-env.sh > .env
# I valori vanno tra apici singoli, quindi letterali; per questo non possono contenere un apice singolo.
set -eu

for chiave in APP_URL APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_WORKSPACE_DOMAIN AMMINISTRATORI MIGRAZIONI_TOKEN; do
    eval "valore=\${$chiave:-}"
    if [ -z "$valore" ]; then
        echo "Manca $chiave tra i segreti o le variabili dell'ambiente." >&2
        exit 1
    fi
done

scrivi() {
    eval "valore=\${$1:-}"
    case "$valore" in
        *"'"*) echo "$1 contiene un apice singolo: non si può scrivere nel .env." >&2; exit 1 ;;
    esac
    printf "%s='%s'\n" "$1" "$valore"
}

echo "APP_NAME='Prima nota'"
echo "APP_ENV=production"
echo "APP_DEBUG=false"
echo "DB_CONNECTION=mysql"
echo "SESSION_DRIVER=database"
echo "CACHE_STORE=database"
echo "QUEUE_CONNECTION=database"
echo "GOOGLE_REDIRECT_URI='${APP_URL}/accesso/google/ritorno'"
for chiave in APP_URL APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_WORKSPACE_DOMAIN AMMINISTRATORI MIGRAZIONI_TOKEN; do
    scrivi "$chiave"
done
