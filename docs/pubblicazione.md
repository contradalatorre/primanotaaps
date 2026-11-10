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
| Configurazione | schermata di consenso OAuth (Google Cloud) | tipo **Esterno**, stato di pubblicazione **In produzione**, solo gli scope `openid`, `email` e `profile` (non sensibili: nessuna verifica degli scope; la verifica del brand serve solo per mostrare nome e logo). *Interno* blocca (`org_internal`) gli account fuori dall'organizzazione, come quelli esterni di #35; *Test* ammette solo gli utenti di prova elencati a mano (al massimo 100) |
| Configurazione | client OAuth (Google Cloud) | tra gli *URI di reindirizzamento autorizzati* registra `<SITO_URL>/accesso/google/ritorno`, uno per ambiente (produzione e prova). Se manca, Google risponde `redirect_uri_mismatch` e nessuno entra |
| Segreto | `AMMINISTRATORI_INIZIALI` | email di chi diventa Amministratore al primo accesso, separate da virgola: vale solo finché nell'app non è mai stato assegnato il ruolo di Amministratore, e una volta sola (lo ricorda il registro degli accessi, evento `amministratore_iniziale`; #35). Dopo l'avvio conviene svuotarla |
| Segreto | `EMERGENZA_PASSWORD_HASH`, `EMERGENZA_TOTP_SECRET` | hash della password e segreto TOTP dell'account di emergenza (#36), diversi per ogni ambiente: si generano con `docker compose run --rm tools php artisan emergenza:credenziali` (vedi sotto) |
| Segreto | `MAIL_USERNAME`, `MAIL_PASSWORD` | account SMTP della posta del Workspace da cui parte l'avviso di accesso di emergenza (#36; lo riuserà anche #46). `MAIL_PASSWORD` dev'essere una **password per le app** di Google (l'account deve avere la verifica in due passaggi): con la password normale Gmail risponde 535 e l'avviso non parte, senza che nessuno se ne accorga |
| Segreto | `MIGRAZIONI_TOKEN` | stringa lunga e casuale, per la prima installazione |
| Segreto | `CRON_TOKEN` | stringa lunga e casuale (almeno 32 caratteri, solo lettere e cifre), per il cron HTTP (vedi *Compiti periodici*), diversa per ogni ambiente |
| Segreto (facoltativo) | `DRIVE_CREDENZIALI` | il file JSON del service account Google per il drive condiviso (#68), codificato in base64 su una riga (`base64 -w0 chiave.json`). Senza, l'app funziona ma il backup non parte e lo stato del sistema lo dice. Un service account non ha spazio suo: la cartella del backup deve stare in un **drive condiviso** (Shared Drive) di cui il service account è membro (*Gestore dei contenuti*); in una cartella di un Drive personale il caricamento fallirebbe con `storageQuotaExceeded`. L'app chiede lo scope `drive.file`; da verificare alla prima prova reale che basti a scrivere in una cartella creata da altri, altrimenti si passa a `https://www.googleapis.com/auth/drive` (costante `SCOPE` in `App\Drive`) |
| Variabile (facoltativa) | `DRIVE_CARTELLA_BACKUP` | l'id della cartella del drive condiviso dove va il backup (la parte finale dell'indirizzo della cartella) |
| Variabile (facoltativa, ma su Aruba serve) | `DB_PROVA_DATABASE` | il nome del secondo database MySQL dello spazio di prova di «Impara» (#71, ADR 0003), creato a mano dal pannello con gli stessi privilegi del primo. Senza, l'app usa `<DB_DATABASE>_prova`, che su Aruba non esiste e non si può creare da codice: «Impara» spiega allora che la prova non è configurata. Diverso per produzione e prova |
| Variabile | `SITO_URL` | indirizzo dell'app senza barra finale, es. `https://sito.example/contabilita` |
| Variabile | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_FROM_ADDRESS` | trasporto della posta: `smtp`, il server SMTP del Workspace (per Gmail `smtp.gmail.com`, porta `465`: TLS implicito, Laravel sceglie `smtps` da solo) e il mittente. Senza `MAIL_MAILER=smtp` Laravel scrive le email nel log e non le spedisce |
| Variabile | `GOOGLE_WORKSPACE_DOMAIN` | dominio del Workspace |
| Variabile | `FTP_CARTELLA` | `contabilita/` oppure `contabilita-test/` |

Nessun valore va scritto nel repo. I valori non possono contenere l'apice singolo `'`.

Di suo `scripts/scrivi-env.sh` scrive anche `SESSION_PATH` (il percorso di `SITO_URL`) e `QUEUE_CONNECTION=sync`. Il primo tiene separati i cookie di produzione e prova, che stanno sullo stesso host; il secondo serve perché su Aruba non gira nessun worker (ADR 0001) e le email messe in coda (oggi nessuna, ma #46 ne manderà) non partirebbero mai.

## Estensioni PHP richieste

Da verificare sul piano Aruba (pannello, o `phpinfo()` da togliere subito dopo) prima della prima pubblicazione: `fileinfo` (controllo del contenuto dei file caricati: senza, l'import degli estratti, #40, rifiuta il file con un messaggio che lo dice), `gd` (immagini dei PDF con mPDF, #35), `zip` (lo zip del backup, #68: senza, il backup fallisce con un messaggio che lo dice), più `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json` che Laravel dà per scontate. Docker locale le ha tutte (`docker/web/Dockerfile`).

## Account di emergenza

Serve quando Google non funziona o non resta nessun Amministratore (`/accesso/emergenza`). Il comando `emergenza:credenziali`, da lanciare in locale e mai sul server, stampa una password lunga, il suo hash e il segreto TOTP. La password e il segreto (da aggiungere a un'app di autenticazione) restano all'associazione, per esempio in una busta chiusa; sui segreti di GitHub vanno solo l'hash e il segreto TOTP. Se si perdono o si temono compromessi, si rigenerano e si ripubblica.

Ogni uso, riuscito o fallito, finisce nel registro degli accessi e quelli riusciti avvisano per email gli Amministratori (dalla posta configurata con `MAIL_*`, con un timeout di 5 secondi: se la posta è giù l'accesso funziona lo stesso e l'errore va nel log); la sessione di emergenza dura un'ora. L'app non può sapere se Google è davvero fuori uso: il controllo è l'avviso agli Amministratori.

## Migrazioni

- **Aggiornamenti**: dopo una pubblicazione con cambiamenti allo schema, un Amministratore apre *Migrazioni* nel menu e preme "Avvia le migrazioni".
- **Prima installazione**: a database vuoto nessuno può ancora entrare, perché mancano le tabelle degli utenti e delle sessioni. Ci pensa il workflow: dopo il caricamento chiama `<SITO_URL>/manutenzione/migrazioni/prima-installazione` con il `MIGRAZIONI_TOKEN`. La chiamata funziona solo finché la tabella `migrations` non esiste o è vuota; dopo risponde 409 e non fa nulla (scelta registrata nell'ADR 0002). Se le migrazioni falliscono risponde 424 e il passo del workflow diventa rosso; il workflow non ripete mai la chiamata.
- **Prima installazione fallita**: il database resta migrato a metà, e rilanciando il workflow la chiamata risponderebbe 409 (verde, ma senza le tabelle mancanti). Si ripristina a mano: dal pannello Aruba (phpMyAdmin) si cancellano tutte le tabelle del database dell'ambiente, si legge il motivo nel log dell'app (`storage/logs`, via FTPS) e si rilancia la pubblicazione. L'endpoint non cancella mai tabelle da solo.

## Compiti periodici

Su Aruba c'è solo il cron come chiamata HTTP (ADR 0001). Nel pannello Aruba si crea, per ciascun ambiente, un cron che ogni 10 minuti chiama `<SITO_URL>/manutenzione/cron?token=<CRON_TOKEN>`: l'app avvia da sola i compiti scaduti dall'ultima chiamata (l'elenco e le scadenze sono in `App\CompitiPeriodici`), una volta sola anche dopo giorni di silenzio. La risposta è un JSON con i comandi avviati. Oggi sono tre: la pulizia degli spazi di prova (a mezzanotte), il riepilogo email delle menzioni (alle 8, ora dell'app) e il backup mensile (il primo del mese alle 3). Il token sta nell'indirizzo perché il cron di Aruba non sa mandare intestazioni: finisce nei log di accesso del sito, quindi va tenuto solo lì, cambiato se trapela, e non va riusato altrove. Senza `CRON_TOKEN` l'endpoint risponde sempre 403. Su una VPS basterà `schedule:run` ogni minuto.

Le email delle menzioni usano la posta di `MAIL_*` e non contengono mai il testo della nota. Dopo la pubblicazione di #46 serve avviare le migrazioni dalla pagina *Migrazioni*.

## Backup (#68)

Il backup è uno zip con `database.sql` (per ricostruire il database senza `mysqldump`) e un CSV per tabella; contiene dati personali, quindi sta solo nella cartella del drive condiviso e in un file temporaneo di `storage/app/private/backup-tmp` che si cancella a fine lavoro. Non entrano sessioni, cache, il token di Google degli utenti né lo spazio di prova. Parte da solo il primo del mese e un Amministratore lo avvia dalla pagina *Sistema*. Se fallisce (drive non configurato o non raggiungibile, zip mancante) l'app va avanti, la pagina *Sistema* mostra l'errore e gli Amministratori ricevono un'email (la stessa posta di `MAIL_*`). Dopo la pubblicazione di #68 serve avviare le migrazioni dalla pagina *Migrazioni*.

Per ricostruire: si scompatta lo zip e si carica `database.sql` in un database vuoto (phpMyAdmin o `mysql nome_database < database.sql`).

## Prove a secco

Il workflow non si lancia a mano. Si prova in locale con Docker: `sh scripts/prova-scrivi-env.sh` per il `.env`, e `docker compose up -d --wait web` seguito da `sh scripts/controlla-pubblicazione.sh http://localhost:8080/contabilita` per il controllo di raggiungibilità.
