#!/bin/sh
# Prova a secco di scrivi-env.sh con valori inventati: sh scripts/prova-scrivi-env.sh
set -u
cd "$(dirname "$0")"

export APP_URL=https://sito.example/contabilita APP_KEY=base64:abc DB_HOST=db DB_DATABASE=d DB_USERNAME=u
export DB_PASSWORD='p#a$s"w' GOOGLE_CLIENT_ID=i GOOGLE_CLIENT_SECRET=s GOOGLE_WORKSPACE_DOMAIN=associazione.example
export AMMINISTRATORI_INIZIALI=maria@associazione.example MIGRAZIONI_TOKEN=t
export EMERGENZA_PASSWORD_HASH='$2y$12$abcDEF.ghi/JKL' EMERGENZA_TOTP_SECRET=JBSWY3DPEHPK3PXP

fallimenti=0
verifica() { if ! eval "$1"; then echo "FALLITO: $2" >&2; fallimenti=1; fi; }

env_scritto=$(sh ./scrivi-env.sh)
verifica 'echo "$env_scritto" | grep -qxF "DB_PASSWORD='"'"'p#a\$s\"w'"'"'"' "la password con # \$ e \" è scritta alla lettera"
verifica 'echo "$env_scritto" | grep -qxF "EMERGENZA_PASSWORD_HASH='"'"'\$2y\$12\$abcDEF.ghi/JKL'"'"'"' "l hash bcrypt dell account di emergenza è scritto alla lettera"
verifica '! (EMERGENZA_TOTP_SECRET= sh ./scrivi-env.sh >/dev/null 2>&1)' "il segreto TOTP mancante è rifiutato"
verifica 'echo "$env_scritto" | grep -qxF "GOOGLE_REDIRECT_URI='"'"'https://sito.example/contabilita/accesso/google/ritorno'"'"'"' "il redirect di Google segue APP_URL"
verifica '! (DB_PASSWORD="a'"'"'b" sh ./scrivi-env.sh >/dev/null 2>&1)' "un apice singolo in un valore è rifiutato"
verifica '! (DB_PASSWORD="$(printf "a\nb")" sh ./scrivi-env.sh >/dev/null 2>&1)' "un a capo in un valore è rifiutato"
verifica 'APP_URL=https://sito.example/contabilita/ sh ./scrivi-env.sh | grep -qxF "GOOGLE_REDIRECT_URI='"'"'https://sito.example/contabilita/accesso/google/ritorno'"'"'"' "la barra finale di APP_URL non finisce nel redirect"
verifica '! (DB_HOST= sh ./scrivi-env.sh >/dev/null 2>&1)' "un segreto mancante è rifiutato"

exit "$fallimenti"
