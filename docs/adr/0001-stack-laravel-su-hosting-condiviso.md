# Laravel su hosting condiviso Aruba, con app portabile

L'app gira oggi sull'hosting Linux condiviso Aruba (piano Easy, intestato all'associazione, scadenza 25/10/2027) perché è già pagato e il vincolo è un costo prossimo a zero. Quel piano offre solo PHP 8.x, MySQL 8, Apache con `.htaccess`, accesso FTP, cron come chiamata HTTP e nessuna cartella scrivibile fuori dallo spazio pubblico. Per questo abbiamo scelto **PHP con Laravel**, pagine generate dal server, dipendenze e stili costruiti su GitHub e pubblicati via FTPS, e l'app in una cartella `contabilita/` dello spazio del sito, raggiunta da un sottodominio HTTPS che vi rimanda. Un `.htaccess` espone solo `public/` e blocca configurazione, codice, log e backup. Docker si usa solo in locale, come copia dell'ambiente Aruba.

L'app resta **portabile**: niente funzioni proprie di Aruba, compiti periodici scritti come comandi Laravel. Il passaggio a una VPS, in valutazione, cambierebbe solo la pubblicazione (SSH o Docker al posto dell'FTP) e l'avvio dei compiti periodici (cron di sistema), non il codice.

## Considered Options

- **Node.js o Python moderni**: esclusi, il piano non li supporta (Python solo come CGI).
- **Framework minimale o PHP senza framework**: più leggero, ma accesso, migrazioni e moduli andrebbero scritti a mano; Laravel è più documentato e più facile da riprendere per un futuro tesoriere volontario.
- **Applicazione JavaScript separata (React, Vue)**: più complessa da mantenere senza vantaggi per un'app di registrazione e revisione.
- **Piano Advanced (SSH, Git, Composer)**: non necessario finché la pubblicazione via FTP funziona.

## Consequences

- Le migrazioni del database si avviano da una pagina protetta, non da riga di comando.
- La pubblicazione su Aruba attende il passaggio del sito da Joomla (PHP 5.6) a WordPress con PHP 8.x, perché la versione di PHP vale per tutto l'hosting.
- Accesso con Google sugli account del Workspace dell'associazione; ruoli assegnati nell'app. Backup mensile su un drive condiviso tramite un service account. Le chiamate HTTPS in uscita verso Anthropic e Google sono state verificate il 31/10/2026.
