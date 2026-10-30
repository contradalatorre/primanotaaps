# Formato dell'estratto conto PayPal

Ricerca per il ticket #4 della mappa "Webapp prima nota APS" (#1).
Domanda: come si scaricano i **movimenti** di un **conto** PayPal Business (report Attività CSV, altri report, API Transaction Search) e come vi compaiono tariffe, rimborsi, trasferimenti con la banca, addebiti preautorizzati, conversioni di valuta e pagamenti in sospeso.

Tutte le fonti sono state consultate il **30/10/2026**. Le fonti ufficiali sono in fondo, numerate [F1]…[F12]. Dove una cosa viene solo da forum o guide di terzi, lo dico esplicitamente ("non verificato su fonte ufficiale").

Nessun dato reale dell'associazione è usato: le righe di esempio sono inventate.

## Sintesi

1. **Via consigliata per la prima versione: il report "Attività" (Activity Download) in CSV**, scaricato a mano dal sito PayPal. È gratuito, copre fino a 7 anni, al massimo 12 mesi per file, 50.000 righe per file [F1][F2]. Ha colonne Lordo/Tariffa/Netto, Saldo progressivo, Tipo, Stato, ID transazione, ID transazione di riferimento e "Impatto sul saldo" (Debito/Credito/Memo) [F1].
2. **Per la prima nota contano solo le righe che incidono sul saldo**. Nel CSV si riconoscono dalla colonna `Balance Impact` = Debit/Credit; le righe `Memo` (autorizzazioni, richieste, sospesi non ancora contabilizzati) vanno escluse o tenute da parte [F1]. L'estratto conto mensile ufficiale esclude esplicitamente i sospesi [F3].
3. **Un'operazione PayPal può generare più righe**: rimborso (riga propria che punta all'originale tramite `Reference Txn ID`), conversione di valuta (righe in entrambe le valute), pagamento finanziato dalla banca (versamento bancario + pagamento). L'importatore deve raggrupparle o classificarle, non trattarle come movimenti indipendenti. Il raggruppamento esatto nel CSV è **non verificato su fonte ufficiale** (serve un estratto di prova).
4. **API Transaction Search**: gratuita da usare (nessun costo dichiarato), ma richiede un'app REST con il permesso "Transaction Search", finestra massima di 31 giorni per chiamata, ritardo fino a 3 ore, storico di 3 anni, 500 righe per pagina [F4][F5]. Utile per un'eventuale automazione futura, non necessaria per l'MVP.
5. **Report SFTP (Transaction Detail, Balance Reconciliation, Settlement)**: solo per commercianti abilitati da PayPal, con account SFTP [F6][F7][F8]. Sproporzionati per un'APS.

## 1. Report Attività (Activity Download) — CSV

### Come si ottiene

- Dal sito PayPal: Attività → icona download → report personalizzato; scegliere periodo e formato (PDF, CSV, TAB, QuickBooks, Quicken) [F2].
- Periodo: qualunque intervallo negli **ultimi 7 anni**, **massimo 12 mesi per richiesta** [F1][F2].
- I file grandi possono richiedere **fino a 5 giorni lavorativi**; PayPal conserva solo gli ultimi 3 file grandi; quelli piccoli si scaricano subito e non vengono salvati [F2].
- CSV/TAB: **max 50.000 record per file**; oltre, più file in uno ZIP. Codifica **UTF-8** [F1].
- Nelle versioni precedenti dell'interfaccia si sceglieva il "Tipo di transazione" (es. "Incidenza sul saldo" / *Balance affecting*, oppure tutte). La presenza di questa scelta nell'interfaccia attuale è **non verificata su fonte ufficiale** (solo forum PayPal Community, con segnalazioni che l'opzione è stata spostata o rimossa). In ogni caso la colonna `Balance Impact` permette di filtrare a valle.
- Nomi dei menu in italiano: **non verificati** (le pagine ufficiali consultate sono in inglese).

### Colonne principali

Il report ha 87 colonne possibili; alcune obbligatorie, altre selezionabili [F1]. Quelle utili alla prima nota:

| Colonna (nome inglese della specifica) | Contenuto | Note |
|---|---|---|
| Date, Time, TimeZone | Data e ora di completamento | Formato data secondo il paese dell'account (MM/DD/YYYY per gli USA); per l'Italia presumibilmente GG/MM/AAAA, **non verificato** |
| Name | Nome della controparte | Non univoco |
| Type | Descrizione del codice evento (T-code) | Testo localizzato, non univoco |
| Status | Stato | Completed, Denied, Reversed, Pending, Active, Expired, Unverified, Voided, Processing, Processed, Created, Canceled |
| Currency | Valuta ISO-4217 | |
| Gross | **Lordo** | |
| Fee | **Tariffa** | Mai ripartita su più transazioni |
| Net | **Netto** | Di norma Lordo − Tariffa (la tariffa compare negativa) |
| From Email Address / To Email Address | Email di pagatore e destinatario | Dato personale: non va mai nel repo |
| Transaction ID | **ID transazione**, 17 caratteri | Obbligatorio |
| Reference Txn ID | ID della transazione "madre" | Collega rimborsi, conversioni, storni all'originale |
| Balance | **Saldo** progressivo disponibile, nella valuta della riga | |
| Balance Impact | Debit / Credit / **Memo** | Memo = non incide sul saldo |
| Subject, Note | Oggetto e messaggio del pagamento | Utile come **descrizione** |
| Invoice Number | Numero fattura del commerciante | |
| Transaction Event Code | **Codice T** (es. T0013) | Stabile e non localizzato: è la chiave giusta per classificare |
| Bank Reference ID | Riferimento bancario | Utile per abbinare i trasferimenti al c/c |
| Payment Source | Fonte di finanziamento (PayPal, carta, eCheck, …) | |

Fonte: [F1]. Raccomandazione: chiedere a chi scarica di includere `Transaction Event Code` e `Balance Impact` (colonne obbligatorie la seconda, selezionabile la prima — da verificare nell'interfaccia).

### Riga di esempio (inventata)

```csv
"Date","Time","TimeZone","Name","Type","Status","Currency","Gross","Fee","Net","Transaction ID","Reference Txn ID","Balance","Balance Impact","Transaction Event Code"
"14/03/2026","18:22:05","CET","Mario Esempio","Pagamento donazione","Completato","EUR","20,00","-0,85","19,15","1AB23456CD789012E","","145,15","Credit","T0013"
```

Separatore decimale, intestazioni e testi di `Type`/`Status` localizzati in italiano: **non verificati**; l'importatore dovrebbe riconoscere le colonne per nome e accettare sia la virgola sia il punto.

## 2. Come compaiono i casi particolari

Codici evento (T-code) dalla tabella ufficiale [F9]; sono gli stessi nel CSV (`Transaction Event Code`), nell'API (`transaction_event_code`) e nei report SFTP.

| Caso | T-code | Comportamento | Verifica |
|---|---|---|---|
| Pagamento ricevuto | T0000 generico, T0006 Express Checkout, T0007 web standard, T0011 mobile, **T0013 donazione** | Riga Credit con Lordo, Tariffa, Netto | Codici: ufficiale [F9] |
| Tariffa | T0107 tariffa su pagamento, T0100 tariffa generica, T0106 chargeback, T0114 contestazione | Normalmente la tariffa sta nella colonna `Fee` della stessa riga; le tariffe non legate a un pagamento sono righe proprie [F6][F9] | Ufficiale |
| **Rimborso** | T1107 rimborso pagamento, T1109 rimborso tariffa | Riga propria Debit, `Reference Txn ID` = transazione originale; la transazione originale passa a stato *Reversed* (V) o *Partially refunded* (F nei report SFTP) [F4][F6][F8] | Collegamento e stati: ufficiale. Se la tariffa rimborsata compare nella stessa riga o in una T1109 separata: **non verificato** |
| **Trasferimento verso la banca** | T0400 prelievo generico, T0401 auto-sweep, T0403 prelievo manuale | Riga Debit; per l'app è metà di un **giroconto** PayPal → c/c | Codici: ufficiale [F9] |
| **Trasferimento dalla banca** | T0300 versamento bancario, T0302 ACH, T0303 EFT | Riga Credit; altra metà di un giroconto | Codici: ufficiale [F9] |
| **Addebiti preautorizzati / ricorrenti** (pagamenti che l'associazione fa a un fornitore autorizzato) | T0003 pagamento preautorizzato di bolletta ricorrente, T0002 pagamento abbonamento | Riga Debit. Nei report SFTP il riferimento alla autorizzazione ha tipo `PAP` (preapproved) o `SUB` (abbonamento) [F6] | Codici: ufficiale. Quando il saldo PayPal non basta e PayPal preleva dal c/c (addebito SEPA), compare in più una riga di versamento bancario (T0300) prima del pagamento: **non verificato su fonte ufficiale** — da confermare con un estratto reale |
| **Conversione di valuta** | T0200 generica, T0201 avviata dall'utente, T0202 per coprire saldo negativo | Ogni valuta ha il proprio saldo; la conversione produce righe nelle due valute (una in uscita, una in entrata) legate tramite `Reference Txn ID` | Codici e saldo per valuta: ufficiale [F1][F9]. Struttura esatta delle righe: **non verificata** (solo guide di terzi) |
| **Pagamenti in sospeso / trattenute** | T1500 / T1503 / T2101 / T2107 / T2105 / T2103 (trattenute e riserve), T2104 / T2108 (rilascio) | Stato *Pending* (P) finché un altro processo non lo completa (es. eCheck/ACH) [F4]. Gli estratti conto mensili **escludono** autorizzazioni non catturate, eCheck in sospeso e contestazioni aperte [F3]. Le trattenute spostano denaro tra saldo disponibile e saldo trattenuto | Ufficiale. Se nel CSV la trattenuta appare come coppia di righe Debit/Credit o come Memo: **non verificato** |
| Chargeback / contestazione | T1201 chargeback, T1202 storno chargeback, T1110 trattenuta per contestazione | Righe proprie | Codici: ufficiale [F9] |

Conseguenza per il modello: **la classificazione automatica dovrebbe basarsi sul T-code**, non sul testo di `Type` (localizzato e mutevole). I T-code T03xx/T04xx sono candidati naturali a **giroconto** con il c/c bancario.

## 3. Altri report

| Report | Come si ottiene | Note | Fonte |
|---|---|---|---|
| **Estratto conto mensile / personalizzato** | Sito PayPal, `paypal.com/reports/accountStatements` | PDF e CSV; saldo iniziale e finale, totali, solo transazioni che incidono sul saldo (**sospesi esclusi**); mensile disponibile dopo il 2 del mese; personalizzato su qualunque periodo degli ultimi 3 anni; CSV fino a 150.000 righe | [F2][F3] |
| Balance Reconciliation Report (BRR) | Sito e SFTP | Giornaliero; importi rimborsati con segno meno e `Related Record ID` verso la vendita; `Transfer ID` abbinabile all'estratto della banca; colonne per cambio e tariffe FX | [F7] |
| Transaction Detail Report (TRR/TDR) | **Solo SFTP, solo commercianti approvati** (richiesta a PayPal) | Giornaliero, conservato 45 giorni; include anche righe che non incidono sul saldo; importi senza segno né separatore decimale (100,00 → `10000`) con colonna DR/CR; stati S, D, P, V, F (parzialmente rimborsato), R (in elaborazione) | [F6][F8] |
| Settlement Report (STL) | SFTP, solo commercianti "legacy" | Non rilevante | [F8] |

Per un'APS l'estratto conto mensile CSV è un'alternativa valida al report Attività: ha il vantaggio dei saldi iniziale/finale (utili per la quadratura) e lo svantaggio di uno storico più corto (3 anni). La lista esatta delle sue colonne CSV non è nella documentazione consultata: **non verificata**.

## 4. API Transaction Search

### Endpoint

- `GET /v1/reporting/transactions` — elenco transazioni [F4][F10].
- `GET /v1/reporting/balances` — saldi (totale, disponibile, trattenuto) per valuta, anche a una data (`as_of_time`), fino a 3 anni indietro [F11].
- Esistono anche `get-balance-net-summary` e `get-daily-summary` (riepiloghi per valuta) [F10].

### Accesso

- OAuth 2.0 client credentials con scope `https://uri.paypal.com/services/reporting/search/read` [F4].
- Serve un'app REST nel Developer Dashboard con l'opzione "Transaction Search" attivata; la propagazione del permesso può richiedere ore (fino a 9 ore secondo una fonte PayPal indicizzata ma non riaperta: **da verificare**).
- Richiede quindi di custodire un client ID e un secret dell'account PayPal: è una credenziale da non mettere mai nel repo.

### Parametri e limiti [F4]

- `start_date`, `end_date` obbligatori (RFC 3339); **intervallo massimo 31 giorni**.
- Le transazioni eseguite possono comparire **fino a 3 ore dopo**; storico degli **ultimi 3 anni**.
- `page_size` 1–500 (predefinito 100), `page` da 1.
- `balance_affecting_records_only`: predefinito **Y** (solo righe che incidono sul saldo); `N` per tutte.
- `transaction_status`: **D** negata, **P** in sospeso (es. in attesa di ACH), **S** completata, **V** stornata (rimborsata al mittente).
- `fields`: predefinito `transaction_info`; `all` per avere anche `payer_info`, `cart_info`, `shipping_info`, ecc.
- Se si usa un qualsiasi parametro opzionale, il campo `ending_balance` torna vuoto.
- Un limite di 10.000 record per richiesta compare nei risultati di ricerca come citazione della guida PayPal, ma la pagina non è stata riaperta: **da verificare**.

### Campi di `transaction_info` [F4]

`transaction_id`, `paypal_reference_id` e `paypal_reference_id_type` (collegamento alla transazione madre), `transaction_event_code` (T-code), `transaction_initiation_date`, `transaction_updated_date`, `transaction_amount` (lordo), `fee_amount` (tariffa), `ending_balance`, `available_balance`, `transaction_status`, `transaction_subject`, `transaction_note`, `bank_reference_id`, `invoice_id`, `custom_field`, più importi accessori (sconti, spedizione, tasse, mance). Il **netto non è un campo**: si calcola lordo + tariffa. La controparte sta in `payer_info` (`account_id`, `email_address`, `payer_name`, indirizzo).

### Costi e limiti di frequenza

- Nella documentazione consultata **non compare alcun costo** per l'uso dell'API Transaction Search; l'assenza di costo non è dichiarata esplicitamente: **non verificato**.
- PayPal **non pubblica soglie di rate limit**: dipendono da API, ambiente e circostanze; in caso di superamento risponde `429 RATE_LIMIT_REACHED`; raccomanda backoff esponenziale e cache del token OAuth [F12].

## 5. Cosa non ho potuto verificare

- Nomi dei menu e intestazioni delle colonne del CSV in **italiano**, separatore decimale e formato data per un account italiano.
- Se l'interfaccia attuale permette ancora di scegliere "Incidenza sul saldo" vs "Tutte le transazioni" al momento del download.
- La struttura esatta delle righe nel CSV per: conversione di valuta, rimborso con restituzione parziale della tariffa, pagamento finanziato da addebito sul c/c, trattenute e rilasci.
- La lista colonne del CSV dell'estratto conto mensile.
- Gratuità esplicita dell'API, limite di 10.000 record, tempo di attivazione del permesso.

Tutti questi punti si chiudono con **un estratto di prova scaricato dall'account reale**, analizzato in locale e mai committato (eventualmente ricavandone un campione anonimizzato e inventato per i test).

## Fonti (consultate il 30/10/2026)

- [F1] PayPal Developer, *Activity Download report* — https://developer.paypal.com/reports/activity-download
- [F2] PayPal Centro assistenza, *How do I view and download statements and reports?* — https://www.paypal.com/cshelp/article/help145
- [F3] PayPal Developer, *Monthly and Custom Statements* — https://developer.paypal.com/docs/reports/online-reports/monthly-statement
- [F4] PayPal Developer, *Transaction Search API — List transactions* — https://developer.paypal.com/docs/api/transaction-search/v1/search-get/
- [F5] PayPal Developer, *Transaction Search API Integration Guide* — https://developer.paypal.com/docs/transaction-search (al momento della consultazione restituiva 404; i dati sui limiti sono ripresi da [F4])
- [F6] PayPal Developer, *Transaction Detail Report Specification* — https://developer.paypal.com/docs/reports/sftp-reports/transaction-detail/
- [F7] PayPal Developer, *Balance Reconciliation report* — https://developer.paypal.com/reports/balance.md
- [F8] PayPal Developer, *SFTP reports* — https://developer.paypal.com/docs/reports/sftp-reports/
- [F9] PayPal Developer, *Transaction event codes* — https://developer.paypal.com/docs/transaction-search/transaction-event-codes/
- [F10] PayPal Developer, *Transaction Search API v1* (indice endpoint) — https://developer.paypal.com/docs/api/transaction-search/v1/
- [F11] PayPal Developer, *List all balances* — https://developer.paypal.com/docs/api/transaction-search/v1/balances-get/
- [F12] PayPal Developer, *Rate limiting guidelines* — https://developer.paypal.com/api/rest/reference/rate-limiting.md (e *API responses*, https://developer.paypal.com/api/rest/responses/)

Fonti non ufficiali viste solo come indizi (non usate come prova): thread PayPal Community sul filtro "Balance affecting"; guide di terzi sull'importazione CSV PayPal.
