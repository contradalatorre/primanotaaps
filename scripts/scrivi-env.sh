#!/bin/sh
# Scrive su stdout il .env di un ambiente a partire dalle variabili d'ambiente (i segreti di GitHub).
# Uso: sh scripts/scrivi-env.sh > .env
# I valori vanno tra apici singoli, quindi letterali; per questo non possono contenere un apice singolo
# né andare a capo (tipico di un segreto incollato con l'invio finale).
set -eu

chiavi="APP_URL APP_KEY DB_HOST DB_DATABASE DB_USERNAME DB_PASSWORD GOOGLE_CLIENT_ID GOOGLE_CLIENT_SECRET GOOGLE_WORKSPACE_DOMAIN AMMINISTRATORI_INIZIALI EMERGENZA_PASSWORD_HASH EMERGENZA_TOTP_SECRET MIGRAZIONI_TOKEN CRON_TOKEN MAIL_MAILER MAIL_HOST MAIL_PORT MAIL_USERNAME MAIL_PASSWORD MAIL_FROM_ADDRESS"
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

# Il drive condiviso (backup, #68) è facoltativo, come ogni integrazione esterna: senza, l'app va avanti e lo stato del
# sistema dice che il backup non parte. Se ci sono, le credenziali sono il JSON del service account in base64.
# Anche il database dello spazio di prova (#71, ADR 0003) lo è, ma su Aruba serve quasi sempre: il nome predefinito
# `<DB_DATABASE>_prova` non si può creare da codice, il database si crea a mano dal pannello.
facoltative="DRIVE_CREDENZIALI DRIVE_CARTELLA_BACKUP DB_PROVA_DATABASE"
for chiave in $facoltative; do
    eval "valore=\${$chiave:-}"
    case "$valore" in
        *"'"* | *"${a_capo%.}"*) echo "$chiave contiene un apice singolo o un a capo: non si può scrivere nel .env." >&2; exit 1 ;;
    esac
done
if [ -n "${DRIVE_CREDENZIALI:-}" ] && ! printf '%s' "$DRIVE_CREDENZIALI" | grep -Eq '^[A-Za-z0-9+/=]+$'; then
    echo 'DRIVE_CREDENZIALI deve essere il JSON del service account in base64 (una riga sola).' >&2
    exit 1
fi

# Le credenziali di emergenza sbagliate si scoprirebbero solo nell'emergenza: meglio far fallire la pubblicazione.
case "$EMERGENZA_PASSWORD_HASH" in
    '$2y$'*) ;;
    *) echo 'EMERGENZA_PASSWORD_HASH non è un hash bcrypt (deve iniziare con $2y$): rigenerala con emergenza:credenziali.' >&2; exit 1 ;;
esac
if ! printf '%s' "$EMERGENZA_TOTP_SECRET" | grep -Eq '^[A-Z2-7]+=*$'; then
    echo 'EMERGENZA_TOTP_SECRET non è un segreto base32 (A-Z, 2-7): rigeneralo con emergenza:credenziali.' >&2
    exit 1
fi

# Il token del cron finisce in un indirizzo (e nei log di accesso): lungo, e solo caratteri che non vanno codificati.
if ! printf '%s' "$CRON_TOKEN" | grep -Eq '^[A-Za-z0-9]{32,}$'; then
    echo 'CRON_TOKEN deve avere almeno 32 caratteri, solo lettere e cifre.' >&2
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
echo "QUEUE_CONNECTION=sync" # su Aruba solo cron via HTTP, nessun worker (ADR 0001)
echo "SESSION_PATH='$percorso'"
echo "GOOGLE_REDIRECT_URI='${APP_URL}/accesso/google/ritorno'"
for chiave in $chiavi; do
    eval "valore=\${$chiave}"
    printf "%s='%s'\n" "$chiave" "$valore"
done
for chiave in $facoltative; do
    eval "valore=\${$chiave:-}"
    [ -z "$valore" ] || printf "%s='%s'\n" "$chiave" "$valore"
done
