# Pubblicazione su Aruba

`main` va in produzione (cartella `contabilita/`), il branch `prova` nell'ambiente di prova (`contabilita-test/`, con un database a parte). Nessun altro branch pubblica. Lo fa `.github/workflows/pubblica.yml`: lancia i test, costruisce dipendenze e stili, scrive il `.env` dai segreti, carica via FTPS e controlla che configurazione, codice, `storage`, log e backup non siano raggiungibili dal web (`scripts/controlla-pubblicazione.sh`).

La pubblicazione reale aspetta il passaggio del sito a PHP 8.x (ADR 0001).

## Da preparare una volta, per ciascun ambiente

In Settings > Environments crea `produzione` e `prova`. Su ciascuno imposta *Deployment branches* su un solo branch (`produzione` solo `main`, `prova` solo `prova`): senza, un branch qualunque con un workflow modificato leggerebbe i segreti di produzione. Su `produzione` conviene anche chiedere l'approvazione di una persona. In ciascuno:

| Tipo | Nome | Contenuto |
| --- | --- | --- |
| Segreto | `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD` | accesso FTPS di Aruba |
| Segreto | `APP_KEY` | chiave dell'app, diversa per ogni ambiente (`php artisan key:generate --show`) |
| Segreto | `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | database MySQL dell'ambiente: **quello di prova ha un database diverso da quello di produzione** |
| Segreto | `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | credenziali OAuth di Google |
| Segreto | `AMMINISTRATORI_INIZIALI` | email di chi diventa Amministratore al primo accesso, separate da virgola: vale solo finché nell'app non è mai stato assegnato il ruolo di Amministratore (#35) |
| Segreto | `EMERGENZA_PASSWORD_HASH`, `EMERGENZA_TOTP_SECRET` | hash della password e segreto TOTP dell'account di emergenza (#36), diversi per ogni ambiente: si generano con `docker compose run --rm tools php artisan emergenza:credenziali` (vedi sotto) |
| Segreto | `MAIL_USERNAME`, `MAIL_PASSWORD` | account SMTP della posta del Workspace da cui parte l'avviso di accesso di emergenza (#36; lo riuserà anche #46) |
| Segreto | `MIGRAZIONI_TOKEN` | stringa lunga e casuale, per la prima installazione |
| Variabile | `SITO_URL` | indirizzo dell'app senza barra finale, es. `https://sito.example/contabilita` |
| Variabile | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS` | trasporto della posta: `smtp`, il server SMTP del Workspace (per Gmail `smtp.gmail.com`, porta `587`) e il mittente. Senza `MAIL_MAILER=smtp` Laravel scrive le email nel log e non le spedisce |
| Variabile | `GOOGLE_WORKSPACE_DOMAIN` | dominio del Workspace |
| Variabile | `FTP_CARTELLA` | `contabilita/` oppure `contabilita-test/` |

Nessun valore va scritto nel repo. I valori non possono contenere l'apice singolo `'`.

## Account di emergenza

Serve quando Google non funziona o non resta nessun Amministratore (`/accesso/emergenza`). Il comando `emergenza:credenziali`, da lanciare in locale e mai sul server, stampa una password lunga, il suo hash e il segreto TOTP. La password e il segreto (da aggiungere a un'app di autenticazione) restano all'associazione, per esempio in una busta chiusa; sui segreti di GitHub vanno solo l'hash e il segreto TOTP. Se si perdono o si temono compromessi, si rigenerano e si ripubblica.

Ogni uso, riuscito o fallito, finisce nel registro degli accessi e quelli riusciti avvisano per email gli Amministratori (dalla posta configurata con `MAIL_*`, con un timeout di 5 secondi: se la posta è giù l'accesso funziona lo stesso e l'errore va nel log); la sessione di emergenza dura un'ora. L'app non può sapere se Google è davvero fuori uso: il controllo è l'avviso agli Amministratori.

## Migrazioni

- **Aggiornamenti**: dopo una pubblicazione con cambiamenti allo schema, un Amministratore apre *Migrazioni* nel menu e preme "Avvia le migrazioni".
- **Prima installazione**: a database vuoto nessuno può ancora entrare, perché mancano le tabelle degli utenti e delle sessioni. Ci pensa il workflow: dopo il caricamento chiama `<SITO_URL>/manutenzione/migrazioni/prima-installazione` con il `MIGRAZIONI_TOKEN`. La chiamata funziona solo finché la tabella `migrations` non esiste o è vuota; dopo risponde 409 e non fa nulla (scelta registrata nell'ADR 0002). Se le migrazioni falliscono risponde 424 e il passo del workflow diventa rosso; il workflow non ripete mai la chiamata.
- **Prima installazione fallita**: il database resta migrato a metà, e rilanciando il workflow la chiamata risponderebbe 409 (verde, ma senza le tabelle mancanti). Si ripristina a mano: dal pannello Aruba (phpMyAdmin) si cancellano tutte le tabelle del database dell'ambiente, si legge il motivo nel log dell'app (`storage/logs`, via FTPS) e si rilancia la pubblicazione. L'endpoint non cancella mai tabelle da solo.

## Prove a secco

Il workflow non si lancia a mano. Si prova in locale con Docker: `sh scripts/prova-scrivi-env.sh` per il `.env`, e `docker compose up -d --wait web` seguito da `sh scripts/controlla-pubblicazione.sh http://localhost:8080/contabilita` per il controllo di raggiungibilità.
