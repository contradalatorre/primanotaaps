# Spazio di prova: un database separato, un prefisso di tabelle per utente

Stato: accettata.

## Contesto

L'onboarding (#21, #71) fa fare pratica sulle schermate vere con dati finti. I dati finti non devono mai finire nei report, nei test, nei backup o nel registro reale, e l'app non deve sapere due volte come funziona (niente schermate simulate). Sull'hosting Aruba Easy (ADR 0001) non si possono creare database da codice: se ne crea a mano un numero fisso dal pannello.

## Decisione

- Una connessione `prova` punta a **un database separato** (`DB_PROVA_DATABASE`, per default `<DB_DATABASE>_prova`) con lo stesso schema del database vero.
- **Una porzione per utente** si ottiene con un **prefisso di tabella** (`p<id>_`): le stesse migrazioni girano con `migrate --database=prova` e il prefisso dell'utente, quindi lo schema è sempre quello vero. I nomi delle tabelle da cancellare si leggono dallo schema e si tengono solo se iniziano esattamente per il prefisso (`p1_` non prende `p12_…`). Mai `migrate:fresh` né `db:wipe` sulla connessione `prova`: cancellerebbero gli spazi di tutti.
- Mentre una richiesta è «in prova» (middleware, sessione con la missione in corso) il **default dell'app passa a `prova`**: controllori, modelli, validazione (`exists:`) e viste non sanno di essere in prova. Il nome della connessione vera resta in `Prova::connessioneReale()`; sessione, cache e coda sono fissate lì, e i modelli dell'avanzamento (`Avanzamento`, `SpazioProva`) dichiarano la connessione vera. Se la connessione `prova` non risponde, la richiesta si ferma: mai si continua sul database vero. Anche l'utente autenticato (caricato da `auth` sul database vero) passa a `prova` per la durata della richiesta (`Prova::entra`/`esci`): le sue assegnazioni e i suoi salvataggi, come le preferenze, valgono per lo spazio. Quello che `auth` e i middleware precedenti scrivono su di lui (verifica dell'account Google) resta sulla riga vera.
- Lo scenario ricopia la riga dell'utente (stesso id, senza token né credenziali) e crea utenti finti: le chiavi esterne `user_id` valgono anche in prova.
- I **permessi in prova** sono quelli della missione in corso (`User::puo()` li legge da `Prova`), non quelli dell'assegnazione vera: così un candidato tesoriere si allena senza avere il ruolo.
- Nessuna chiamata esterna: durante la richiesta `Http::preventStrayRequests()` e mailer `array`; l'AI è preregistrata nello scenario.
- L'avanzamento, i timbri e l'elenco degli spazi stanno nel database vero (`onboarding_avanzamenti`, `spazi_prova`). La cancellazione degli spazi (`prova:cancella`, giornaliera) lascia tutto questo e tocca gli spazi di chi ha perso ogni assegnazione e di chi non li usa da 6 mesi. La revoca cancella la riga dell'assegnazione, quindi `spazi_prova.con_assegnazione` ricorda se lo spazio è nato con un'assegnazione attiva: chi si allena prima di avere il ruolo (candidato) non è «chi l'ha perso».

## Conseguenze

- Sul server serve un secondo database MySQL (`DB_PROVA_DATABASE`) con gli stessi privilegi del primo; senza, l'app funziona ma «Impara» spiega che la prova non è configurata.
- Lo schema di prova segue le migrazioni vere: a ogni avvio di missione si rilancia `migrate` sul prefisso dell'utente.
- Le revisioni successive non segnalano come errore il cambio del default della connessione dentro `ProvaMiddleware`: è la decisione di questo ADR.
