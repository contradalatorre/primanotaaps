# Capacità dei piani hosting Linux di Aruba

Ricerca per il ticket [#2](https://github.com/contradalatorre/primanotaaps/issues/2) della mappa [#1](https://github.com/contradalatorre/primanotaaps/issues/1).

**Domanda.** Cosa supportano i piani di hosting Linux condiviso di Aruba (la famiglia su cui gira oggi contradalatorre.org): linguaggi e versioni, database, cron, SSH, deploy oltre l'FTP, sottodomini e HTTPS, chiamate HTTPS in uscita, limiti di memoria e tempo, backup.

**Fonti.** Solo pagine prodotto e guide ufficiali Aruba, tutte consultate il **30/10/2026**. Le guide non riportano una data di pubblicazione (solo "© 2026 Aruba S.p.A."). L'elenco completo delle fonti è in fondo; nel testo sono citate come [P1], [G1] e così via.

## In sintesi

- **Lo stack possibile è PHP + MySQL 8.** PHP 8.0–8.5 su Apache 2.4, MySQL 8 (5 database, 1 GB in totale). **Node.js non è supportato** [G6]. Python, Perl e Ruby ci sono, ma solo come script CGI nella cartella `cgi-bin` [G6], quindi niente framework Python moderni (WSGI/ASGI).
- **Il piano minimo utile è Easy.** Basic non include database (sono "opzionali") [P1]. Per SSH, Composer e Git serve **Advanced** [P1][G3].
- **Il cron ha molti vincoli.** Disponibile su tutti i piani, ma con frequenza minima di 10 minuti, orari in UTC e sole due modalità: chiamata HTTP/HTTPS o istruzione PHP [G4].
- **Il database è raggiungibile solo dalla rete Aruba** [G7]. Non ci si può collegare con client desktop, e l'hosting non può collegarsi a database esterni.
- **Non verificato: le chiamate HTTPS in uscita (API Claude).** L'estensione cURL è presente [P1][G6], ma nessuna fonte ufficiale dice se le connessioni in uscita verso host esterni sulla porta 443 sono permesse. Va verificato con un test (vedi in fondo).

## Tabella per piano (Hosting Linux condiviso)

Valori dalla tabella comparativa della pagina prodotto [P1][P2], salvo dove indicato diversamente.

| Caratteristica | Basic | Easy | Advanced |
|---|---|---|---|
| Prezzo (+ IVA) | 9,90 € il 1° anno, rinnovo da 34,99 €/anno | 9,90 € il 1° anno, rinnovo da 59,99 €/anno | 19,90 € il 1° anno, rinnovo da 79,99 €/anno |
| Web server | Apache 2.4 | Apache 2.4 | Apache 2.4 |
| PHP | 8.0, 8.1, 8.2, 8.3, 8.4, 8.5 | come Basic | come Basic |
| Estensioni PHP dichiarate | ImageMagick, IonCube, Sodium, Zlib, cURL, GD, OPCache, APCu, Redis; .htaccess, mod_rewrite | come Basic | come Basic |
| php.ini personalizzabile | sì, entro valori predefiniti [G1][G2] | sì | sì |
| Node.js | **no** [G6] | **no** | **no** |
| Python / Perl / Ruby | 2.7 e 3.x / 5.16 / 2.7.x, solo CGI in `cgi-bin` [G6] | come Basic | come Basic |
| Database MySQL 8 | 5, **opzionali** (a pagamento) | 5, 1 GB totali su SSD | 5, 1 GB totali su SSD |
| Accesso al DB | solo dalla rete Aruba [G7] | come Basic | come Basic |
| Cron | sì (min. 10 min, UTC, HTTP/HTTPS o PHP) [G4] | sì | sì |
| SSH | **no** | **no** | **sì**: fino a 5 utenti con chiave pubblica [G3] |
| Git / Composer | no (senza SSH) | no (senza SSH) | sì da SSH; **non** si può clonare via protocollo GIT/SSH [G3] |
| Deploy | FTP (con restrizione per IP), File Manager | come Basic | FTP, File Manager, SSH |
| Sottodomini e redirect | sì | sì | sì |
| HTTPS | certificato SSL DV incluso e preinstallato | come Basic | come Basic |
| HTTP/2 e HTTP/3 | sì | sì | sì |
| CDN | no | sì | sì |
| Spazio web e traffico | illimitati (banda 2 Gbit/s best effort) | come Basic | come Basic |
| Backup spazio web | giornaliero, "1-7 giorni" di conservazione; non include i database [G8][G9] | come Basic | come Basic |
| Backup database | "con backup" secondo la pagina prodotto; servizio "Backup database MySQL" distinto [G8] | come Basic | come Basic |
| HTTPS in uscita | **non verificato** | **non verificato** | **non verificato** |

### Limiti PHP (tutti i piani condivisi)

Valori selezionabili da pannello, secondo la guida [G1].

| Direttiva | Valori ammessi |
|---|---|
| `memory_limit` | 128, 256, 512, 1024 MB |
| `max_execution_time` | 120, 240, 300, 600, 900 (unità non indicata; presumibilmente secondi) |
| `max_input_time` | 60, 90, 120, 300 |
| `upload_max_filesize` | 25, 128, 256, 512, 1024 MB |
| `post_max_size` | 25, 128, 256, 512 MB |
| `max_input_vars` | 1.000, 2.500, 5.000, 10.000 |

Funzioni come `exec` e `proc_open` sono disattivate di default e si possono abilitare dal php.ini [G6]. PHP gira in modalità CGI, non come modulo Apache [G6].

## Alternativa con risorse dedicate: Hyper Hosting Linux

199,00 € + IVA il 1° anno, rinnovo da 390,00 €/anno [P1]. Secondo la guida [G5]:

- 8 CPU e 16 GB di RAM dedicati
- 2 database **MariaDB** con spazio illimitato e fino a 200 connessioni
- `memory_limit` fino a 1 GB e 100 PHP worker
- SSH con limite di memoria di 1 GB
- cron con frequenza minima di 5 minuti e **timeout di 60 secondi**

Neanche qui la documentazione dice che Node.js è disponibile.

## Cosa pesa sulla scelta dello stack

1. **Linguaggio lato server: PHP.** È l'unico runtime di prima classe. Con Node.js escluso e Python solo via CGI, lo stack naturale è PHP 8.x, con o senza framework leggero. Un frontend JS va compilato in locale e pubblicato come file statici.
2. **Deploy.** Su Easy si pubblica solo via FTP o File Manager. Su Advanced c'è SSH con Git e Composer, ma il clone via protocollo GIT/SSH è escluso [G3]. Resta da verificare se il clone via HTTPS funziona: dipende anche dalle connessioni in uscita. In alternativa si può fare un deploy FTP/SFTP da una GitHub Action.
3. **Database.** Vanno bene MySQL 8 e InnoDB. Il limite di 1 GB è ampio per una prima nota. Dato che il DB è accessibile solo dalla rete Aruba, migrazioni e amministrazione si fanno dal pannello MySQL di Aruba o da script PHP sul server.
4. **Lavori pianificati.** Il cron consente al massimo un'esecuzione ogni 10 minuti, in UTC, e lancia una URL o uno script PHP. Basta per promemoria o elaborazioni notturne, non per code di lavoro.
5. **Chiamate all'API Claude.** cURL è disponibile, ma l'uscita verso `api.anthropic.com:443` non è documentata. Il `max_execution_time` (almeno 120, fino a 900) è sufficiente per una singola chiamata.

## Non verificato

- **Connessioni HTTPS in uscita** verso host esterni, cioè firewall e porte in uscita. Le fonti ufficiali dicono solo che l'hosting non si collega a *database* esterni [G7]. Test proposto: uno script PHP temporaneo sull'hosting attuale che fa `curl_init('https://api.anthropic.com/')` e stampa il codice HTTP (anche un 401/404 dimostra che la connessione esce), da cancellare subito dopo. In alternativa si chiede all'assistenza Aruba.
- **Piano attuale di contradalatorre.org.** Non risulta dalle fonti pubbliche. Va letto nell'area clienti Aruba. La guida SSH cita anche un piano "Professional" [G3], che non è più in vendita e potrebbe essere quello di un contratto vecchio.
- **Versioni PHP.** La pagina prodotto riporta 8.0–8.5 [P1], la FAQ 8.0–8.3 [G6]. Probabilmente la FAQ è meno aggiornata.
- **Versione MySQL.** La pagina prodotto dice "versione 8" [P1]; la guida sul database non indica la versione [G7].
- **Conservazione dei backup.** "1-7 giorni" sulla pagina prodotto [P1]. La guida dice che il backup giornaliero sovrascrive quello del giorno prima [G9] ed esiste anche un backup settimanale. Non è chiaro quanti giorni siano davvero recuperabili, né come funziona il backup dei database in Easy e Advanced.
- **Limiti mancanti.** Non sono documentati per i piani condivisi: numero massimo di cron, timeout del cron, limiti di CPU e processi, inode, connessioni MySQL simultanee (`max_user_connections`), numero di sottodomini.
- **Porta e host SSH.** Compaiono solo nel pannello [G3]. Non si sa se sono disponibili `rsync`, `scp` e `sftp`.
- **Interpreti da riga di comando via SSH.** Non è documentato se si possono usare `php` o `python3` da shell, per esempio per gli script di Composer.

## Fonti (consultate il 30/10/2026)

- [P1] Aruba, *Hosting Linux* (pagina prodotto in inglese, tabella comparativa): https://hosting.aruba.it/en/web-hosting/linux
- [P2] Aruba, *Hosting Linux* (pagina prodotto in italiano): https://hosting.aruba.it/web-hosting/linux
- [G1] Aruba Guide, *Limiti di configurazione PHP per Hosting Linux*: https://guide.aruba.it/hosting-e-domini/hosting/hosting-linux/pannello-controllo-linux/limiti-configurazione-php-linux
- [G2] Aruba Guide, *Gestione PHP per Linux, WordPress, servizi Gestiti e Hyper Hosting*: https://guide.aruba.it/hosting-e-domini/hosting/hosting-linux/pannello-controllo-linux/Gestione-php
- [G3] Aruba Guide, *Gestione chiavi SSH* (Hosting Linux): https://guide.aruba.it/hosting-e-domini/hosting/hosting-linux/pannello-controllo-linux/gestione-chiavi-ssh
- [G4] Aruba Guide, *Processi Cron*: https://guide.aruba.it/hosting-e-domini/hosting/hosting-linux/pannello-controllo-linux/processi-cron
- [G5] Aruba Guide, *Caratteristiche di Hyper Hosting Linux*: https://guide.aruba.it/hosting-e-domini/hyper-hosting/hyper-hosting-linux/caratteristiche-e-pannello-controllo-hyper-linux/caratteristiche-hyper-hosting-linux
- [G6] Aruba Guide, FAQ *Linguaggi e spazio web Linux*: https://guide.aruba.it/faq/hosting-e-domini/linguaggi-e-spazio-web-linux
- [G7] Aruba Guide, *Caratteristiche e condizioni di utilizzo database* e *Da dove è possibile accedere ai database MySQL e MSSQL*: https://guide.aruba.it/hosting-e-domini/database/attivazione-rinnovo-upgrade-database/caratteristiche-condizioni-utilizzo, https://guide.aruba.it/hosting-e-domini/database/attivazione-rinnovo-upgrade-database/accessibilita-database
- [G8] Aruba Guide, *Caratteristiche del servizio Backup Hosting*: https://guide.aruba.it/hosting-e-domini/backup-sito-web/Gestione-Backup-Hosting/caratteristiche-backup-hosting
- [G9] Aruba Guide, *Funzionamento e recupero del Backup giornaliero*: https://guide.aruba.it/hosting-e-domini/backup-sito-web/Gestione-Backup-Hosting/funzionamento-recupero-backup-giornaliero
