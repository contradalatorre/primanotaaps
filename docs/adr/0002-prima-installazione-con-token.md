# Prima installazione del database con un token, fuori da sessione e CSRF

Stato: accettata.

## Contesto

Su un database vuoto mancano `users` e `sessions`: nessuno può accedere, quindi la pagina Migrazioni protetta dell'ADR 0001 non si può usare per creare lo schema. Sull'hosting Aruba Easy non c'è SSH.

## Decisione

Il workflow di pubblicazione chiama `POST /manutenzione/migrazioni/prima-installazione` con un `Authorization: Bearer` uguale al segreto `MIGRAZIONI_TOKEN` dell'ambiente. L'endpoint sta fuori da sessione, accesso e CSRF: l'unica difesa è il token (confronto a tempo costante, rifiutato se vuoto o non configurato) e il fatto che a installazione avvenuta non fa nulla. Il token compare solo nei segreti dell'ambiente e nel `.env` caricato via FTPS.

- Vale finché la tabella `migrations` non esiste o è vuota; dopo risponde 409.
- Se le migrazioni falliscono risponde 424, che il workflow non accetta (accetta solo 200 e 409).
- Il workflow non ripete mai la chiamata (niente `curl --retry`): dopo un errore che PHP non può catturare, come il tempo massimo di esecuzione, una seconda chiamata riceverebbe 409 con lo schema a metà e il passo risulterebbe verde.
- Non usa mai `db:wipe` né `migrate:fresh`: il ripristino di un database a metà è manuale (docs/pubblicazione.md).

Le revisioni successive non segnalano questo endpoint come accesso non autenticato.
