#!/bin/sh
# Scrive su stdout il .env di un ambiente a partire dalle variabili d'ambiente (i segreti di GitHub).
# Uso: sh scripts/scrivi-env.sh > .env
# I valori vanno tra apici singoli, quindi letterali; per questo non possono contenere un apice singolo
# né andare a capo (tipico di un segreto incollato con l'invio finale).
set -eu

chiavi="APP_URL APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_WORKSPACE_DOMAIN AMMINISTRATORI_INIZIALI EMERGENZA_PASSWORD_HASH EMERGENZA_TOTP_SECRET MIGRAZIONI_TOKEN MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS"
a_capo=$(printf '\n.')

for chiave in $chiavi; do
    eval "valore=\${$chiave:-}"
    if [ -z "$valore" ]; then
        echo "Manca $chiave tra i segreti o le variabili dell'ambiente." >&2
        exit 1
    fi
    case "$valore" in
        *"'"* | *"${a_capo%.}"*) echo "$chiave contiene un apice singolo o un a capo: non si può scrivere nel .env." >&2; exit 1 ;;
    esac
done

# Le credenziali di emergenza sbagliate si scoprirebbero solo nell'emergenza: meglio far fallire la pubblicazione.
case "$EMERGENZA_PASSWORD_HASH" in
    '$2y$'*) ;;
    *) echo 'EMERGENZA_PASSWORD_HASH non è un hash bcrypt (deve iniziare con $2y$): rigenerala con emergenza:credenziali.' >&2; exit 1 ;;
esac
if ! printf '%s' "$EMERGENZA_TOTP_SECRET" | grep -Eq '^[A-Z2-7]+=*$'; then
    echo 'EMERGENZA_TOTP_SECRET non è un segreto base32 (A-Z, 2-7): rigeneralo con emergenza:credenziali.' >&2
    exit 1
fi

# Senza barra finale, come lo vuole anche il controllo di raggiungibilità: il redirect di Google deve combaciare.
APP_URL="${APP_URL%/}"

# Produzione e prova stanno sullo stesso host: il cookie di sessione (e XSRF-TOKEN) va limitato al percorso dell'app,
# se no i due ambienti si buttano fuori a vicenda.
resto="${APP_URL#*://}"
case "$resto" in
    */*) percorso="/${resto#*/}" ;;
    *) percorso=/ ;;
esac

echo "APP_NAME='Prima nota'"
echo "APP_ENV=production"
echo "APP_DEBUG=false"
echo "DB_CONNECTION=mysql"
echo "SESSION_DRIVER=database"
echo "CACHE_STORE=database"
echo "QUEUE_CONNECTION=sync" # su Aruba non gira nessun worker (ADR 0001)
echo "SESSION_PATH='$percorso'"
echo "GOOGLE_REDIRECT_URI='${APP_URL}/accesso/google/ritorno'"
for chiave in $chiavi; do
    eval "valore=\${$chiave}"
    printf "%s='%s'\n" "$chiave" "$valore"
done
