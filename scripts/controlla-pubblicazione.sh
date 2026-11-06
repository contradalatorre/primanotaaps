#!/bin/sh
# Controllo dopo ogni pubblicazione (ADR 0001): configurazione, codice, storage, log e backup
# non devono essere raggiungibili dal web. Uso: controlla-pubblicazione.sh <url-base>
# (senza barra finale, es. https://sito.example/contabilita). Esce con errore al primo percorso raggiungibile.
set -u

base="${1:?Uso: $0 <url-base>}"
base="${base%/}"
errori=0

# Il sito deve rispondere, altrimenti "non raggiungibile" vorrebbe dire solo "non c'è niente".
codice=$(curl -s --retry 10 --retry-delay 2 --retry-connrefused -o /dev/null -w '%{http_code}' "$base/accesso")
echo "accesso -> $codice"
test "$codice" = 200 || errori=1

# Dev'essere 403 o 404 dopo gli eventuali reindirizzamenti (Apache aggiunge la barra finale alle cartelle).
for percorso in \
    .env .env.example composer.json composer.lock artisan \
    config/app.php config/database.php bootstrap/app.php app/Models/User.php \
    database/migrations/0001_01_01_000000_create_users_table.php routes/web.php \
    storage/logs/laravel.log storage/framework/sessions storage/app/private/backup.sql storage/app/private/backup.zip \
    vendor/autoload.php .ftp-deploy-sync-state.json storage/.ftp-deploy-sync-state.json; do
    codice=$(curl -sL -o /dev/null -w '%{http_code}' "$base/$percorso")
    echo "$percorso -> $codice"
    if [ "$codice" != 404 ] && [ "$codice" != 403 ]; then
        echo "ERRORE: $percorso risponde $codice, dovrebbe essere 403 o 404" >&2
        errori=1
    fi
done

exit "$errori"
