# Prima installazione del database con un token, fuori da sessione e CSRF

Stato: accettata.

Su un database vuoto mancano `users` e `sessions`: nessuno può accedere, quindi la pagina Migrazioni protetta dell'ADR 0001 non si può usare per creare lo schema. Sull'hosting Aruba Easy non c'è SSH. Il workflow di pubblicazione chiama perciò `POST /manutenzione/migrazioni/prima-installazione` con un `Authorization: Bearer` uguale al segreto `MIGRAZIONI_TOKEN` dell'ambiente. L'endpoint sta fuori da sessione, accesso e CSRF: l'unica difesa è il token (confronto a tempo costante, rifiutato se vuoto o non configurato) e il fatto che a installazione avvenuta non fa nulla. Il token compare solo nei segreti dell'ambiente e nel `.env` caricato via FTPS.

L'endpoint vale finché la tabella `migrations` non esiste o è vuota; dopo risponde 409. Se le migrazioni falliscono risponde 424, che il workflow non accetta e `curl --retry` non ripete, così un'installazione a metà non passa per riuscita. Non usa mai `db:wipe` né `migrate:fresh`: il ripristino di un database a metà è manuale (docs/pubblicazione.md).

Le revisioni successive non segnalano questo endpoint come accesso non autenticato.
