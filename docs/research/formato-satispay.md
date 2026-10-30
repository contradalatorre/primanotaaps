# Formato dell'estratto conto Satispay Business

Ricerca per il ticket #5 (mappa #1). Domanda: come si ottengono **movimenti** e trasferimenti di un **conto** Satispay Business, quali campi ci sono, come compaiono le commissioni (lordo/netto), quali identificativi esistono e come compaiono i versamenti periodici verso il conto bancario.

Tutte le fonti sono state consultate il **30/10/2026**. Le fonti sono solo documentazione ufficiale Satispay (Help Center `support.satispay.com`, `developers.satispay.com`, `satispay.com`). Dove ho trovato solo fonti di terze parti lo dico esplicitamente.

## In sintesi

1. Satispay non produce un unico "estratto conto": ci sono **due export separati** dal Pannello di Controllo (dashboard): **Transazioni** (incassi con il dettaglio delle commissioni, .csv o .xls) e **Versamenti/Payouts** (accrediti in banca al netto delle commissioni, .csv, .xls o PDF). [S1][S2]
2. I versamenti in banca sono **raggruppati**: un versamento comprende tutte le transazioni di un periodo (di default il giorno precedente), **al netto delle commissioni**. Per ogni versamento la dashboard mostra data/ora, causale, **TRN**, banca e IBAN, e l'elenco delle transazioni incluse. [S2][S3]
3. Via API, l'unica fonte con le **commissioni** è la **Report API** (`POST /g_business/v1/reports`, tipo `PAYMENT_FEE`): restituisce un CSV/XLS con lordo (`total_amount`) e commissione (`fee_amount`) per transazione. Richiede credenziali dedicate attivate a mano da Satispay. [S4]
4. Le API pagamenti (`GET /g_business/v1/payments`) danno solo l'importo lordo, senza commissioni, e un `daily_closure` (id + data). **Non esiste un'API documentata per i versamenti in banca.** [S5][S6][S7]
5. **Non verificato**: il testo esatto con cui il versamento compare nell'estratto della banca (ordinante, descrizione) e l'intestazione esatta delle colonne nei file esportati dalla dashboard.

## Via 1 — Export dal Pannello di Controllo (dashboard)

Accesso da `dashboard.satispay.com` con le credenziali dell'esercente. [S1]

### Export "Transazioni"

- Contenuto: storico delle transazioni incassate **con il dettaglio delle commissioni**. [S1]
- Formati: **.csv** o **.xls**. [S1]
- Colonne esatte: **non documentate** nell'Help Center. È plausibile, ma non verificato, che coincidano con le colonne di default del report `PAYMENT_FEE` della Report API (vedi Via 2), perché anche quel report si chiede come "report" e ha gli stessi due formati.

### Export "Versamenti" (Payouts)

- Contenuto: storico dei versamenti verso il conto bancario, **al netto delle commissioni**. [S1]
- Formati: **.csv**, **.xls** o **PDF**. [S1]
- Procedura: sezione "Payouts" → "Richiedi report" → scelta del formato → il file si scarica dalla sezione "Report" quando è pronto. [S2]
- Per ogni versamento: **data e ora di esecuzione, causale, TRN, banca, riferimento IBAN**, più l'elenco di tutte le transazioni comprese in quell'accredito. [S2]
- Profondità: si consultano i versamenti **fino al mese precedente**. [S2] (Formulazione ambigua: non è chiaro se significa "solo fino a un mese fa" o "tutto fino al mese scorso"; da verificare sulla dashboard reale.)

### Frequenza e raggruppamento dei versamenti

Si imposta in dashboard (Impostazioni → Versamenti → Aggiungi versamento / Payouts → Configure Payouts). Opzioni: [S3]

| Frequenza | Quando arriva l'accredito |
|---|---|
| Giornaliera | giorno lavorativo successivo all'incasso |
| Bisettimanale | lunedì e mercoledì |
| Settimanale | ogni lunedì |
| Mensile | primo giorno lavorativo del mese |

I versamenti sono "al netto delle commissioni applicate". [S3] La pagina "scaricare il report dei versamenti" parla di accredito "del giorno" con l'elenco delle transazioni incluse [S2]: quindi **un versamento = un bonifico che raggruppa più transazioni**, non un bonifico per transazione. Il deposito in banca è gratuito. [S8]

## Via 2 — API (GBusiness API, `developers.satispay.com`)

L'indice ufficiale della documentazione [S7] elenca solo: Payments (create/get/update/list/refund), Meal vouchers, Fringe Benefits, **Merchant Reports** (solo "Create new report"), autenticazione e callback. I flussi "online" (web-button, web-redirect, pagamenti automatici, funds lock) sono **flussi della stessa GBusiness API**, non un'API separata: per la contabilità producono gli stessi oggetti pagamento. **Non c'è alcun endpoint per versamenti, settlement o saldo.**

Tutte le chiamate richiedono firma RSA (KeyID + header `Authorization`, `Digest`, `Date`). [S5]

### 2a. Report API — `POST /g_business/v1/reports` [S4]

È la via API più vicina a un estratto conto con commissioni.

- **Prerequisiti**: coppia di chiavi RSA e KeyID **nuovi e dedicati**, attivati scrivendo a `tech@satispay.com`. I report coprono **tutto l'esercente** (tutti i negozi), non un singolo negozio.
- **Richiesta**: `type: "PAYMENT_FEE"` (unico tipo documentato), `format: "CSV" | "XLS"`, `query_type: "PAYMENT_DATE_INTERVAL"`, `query_payload.payment_date_from` / `payment_date_to` (`YYYY-MM-dd`), `time_zone` opzionale (es. `Europe/Rome`), `columns` opzionale per scegliere/rinominare colonne. Notifica a fine elaborazione via email (`email_cc_list`) o `callback_url`.
- **Tempistica**: generare il report del giorno prima **almeno 4 ore dopo mezzanotte** per non avere dati incompleti.
- **Scaricamento**: la risposta dà un `id` e uno `status` (`PENDING`/`SUCCESS`), ma **la documentazione non descrive l'endpoint per scaricare il file**. Non verificato.

Colonne di default del report `PAYMENT_FEE`:

| Colonna | Significato |
|---|---|
| `transaction_id` | id della transazione (stesso `id` delle API pagamenti) |
| `transaction_date` | data della transazione |
| `shop_name` | nome del negozio |
| `cost_centre` | centro di costo |
| `total_amount` | importo **lordo**, in centesimi |
| `fee_amount` | **commissione**, in centesimi |
| `transaction_type` | `TO_BUSINESS` (incasso) o `REFUND_TO_BUSINESS` (rimborso al cliente) |
| `external_code` | codice impostato dall'esercente alla creazione del pagamento |
| `group_id` | non descritto nella documentazione |

Colonne opzionali utili: `transaction_signed_amount_unit` (negativo per i rimborsi), `transaction_acceptance_date` (UTC), `fee_id`, `fee_type`, `fee_signed_amount_unit`, `fee_transaction_original_id`, `shop_id`, `shop_external_code`. Se attivi buoni pasto / fringe benefit / "Paga in 3" compaiono colonne di importo e commissione separate per prodotto.

**Il netto non è una colonna**: va calcolato come `total_amount - fee_amount`. **Nessuna colonna collega la transazione al versamento in banca** (nessun id di payout/TRN). La documentazione stessa ha incoerenze tra lo schema e l'elenco colonne (alcune colonne `*_unit` documentate non sono tra gli identificativi ammessi): da provare in sandbox.

### 2b. API pagamenti — `GET /g_business/v1/payments` e `GET /payments/{id}` [S5][S6]

- Parametri lista: `status` (`ACCEPTED` di default, `PENDING`, `CANCELED`), `limit` (1–100), paginazione a cursore `starting_after`, `starting_after_timestamp` (millisecondi). Restituisce solo i pagamenti del **negozio** della KeyID usata.
- Campi del pagamento: `id`, `type` (`TO_BUSINESS` / `REFUND_TO_BUSINESS`), `amount_unit` (centesimi, **lordo**), `currency` (sempre `EUR`), `status`, `expired`, `external_code`, `metadata`, `sender` (`id`, `type`, `name` — nome del pagante solo dopo l'abbinamento), `receiver`, `daily_closure` (`id`, `date`; solo per `ACCEPTED` di tipo `TO_BUSINESS`), `insert_date` ed `expire_date` (UTC). `description` e `flow` sono deprecati.
- **Commissioni: assenti.** **Netto: assente.**
- `daily_closure` è la chiusura giornaliera a cui appartiene l'incasso: è il candidato naturale per raggruppare le transazioni per giorno, ma **la documentazione non dice se corrisponde a un versamento in banca** (con frequenza non giornaliera un versamento comprenderebbe più chiusure). Non verificato.
- Attenzione privacy: `sender.name` contiene il nome della persona che ha pagato.

## Identificativi a confronto

| Identificativo | Dove compare | A cosa serve |
|---|---|---|
| `id` / `transaction_id` | API pagamenti, Report API, (probabilmente) export Transazioni | identifica la singola transazione |
| `external_code` | API pagamenti, Report API | codice libero dell'esercente (solo per pagamenti creati via API/plugin) |
| `daily_closure.id` + `date` | API pagamenti | chiusura giornaliera |
| `fee_id` | Report API (colonna opzionale) | identifica la commissione |
| **TRN** | dashboard, sezione Versamenti | identifica il bonifico verso la banca; è il ponte con l'estratto della banca |

## Come compaiono i versamenti nell'estratto della banca

- Ufficiale: un versamento è un bonifico SEPA che raggruppa le transazioni del periodo, al netto delle commissioni, con un **TRN** e una **causale** visibili nella dashboard. [S2][S3]
- **Non verificato**: il testo della descrizione nell'estratto della banca. Non ho trovato documentazione ufficiale sul nome dell'ordinante né sul formato della causale. L'emittente è Satispay Europe S.A. (Lussemburgo); che compaia come ordinante è plausibile ma non confermato da fonti ufficiali. Va verificato confrontando un versamento in dashboard con il movimento corrispondente in banca: il **TRN** è l'aggancio più sicuro, l'importo netto più la data quello di riserva.

Per l'app questo vuol dire che il versamento Satispay→banca è un **giroconto**: l'uscita dal conto Satispay è nell'export Versamenti, il versamento sul conto corrente è nell'estratto della banca, e i due si abbinano con TRN o con importo e data.

## Commissioni (contesto, non strettamente di formato)

La pagina Business di Satispay indica oggi: pagamenti **sotto 10 €: gratis**; **da 10 € in su: 0,95%**; condizioni diverse per e-commerce, tabaccherie/edicole e servizi a valore aggiunto; iscrizione, deposito in banca e cancellazione gratuiti. [S8] Non ho trovato condizioni ufficiali dedicate ad associazioni/APS (alcune fonti di terze parti parlano di donazioni gratuite per il non profit e di "associazioni in convenzione": **non verificato** su fonti ufficiali). Ai fini della prima nota conta che **le commissioni non sono addebitate a parte**: si vedono solo come differenza tra il lordo incassato e il netto versato.

## Esempio inventato

Dati interamente fittizi, per illustrare come si legano i due export.

Transazioni (lordo e commissione per incasso):

```
transaction_id,transaction_date,total_amount,fee_amount,transaction_type
tx-AAA111,2026-03-14,1500,14,TO_BUSINESS
tx-BBB222,2026-03-14,800,0,TO_BUSINESS
tx-CCC333,2026-03-14,-500,0,REFUND_TO_BUSINESS
```

Versamento corrispondente (bonifico giornaliero il 16/03, giorno lavorativo successivo): netto = (1500 − 14) + 800 − 500 = **1786 centesimi = 17,86 €**, TRN fittizio `TRN-0000-EXAMPLE`. In banca arriva un solo movimento in entrata da 17,86 €. Come vengono trattate le commissioni in caso di rimborso va verificato su un file reale: la documentazione non dice se un rimborso restituisce o no la commissione.

## Cosa resta da verificare (con un account reale, fuori da questo repo pubblico)

1. Intestazioni esatte delle colonne degli export dashboard "Transazioni" e "Versamenti" (CSV), separatore, formato di data e importi (euro con virgola o centesimi?).
2. Testo della descrizione del versamento nell'estratto della banca (ordinante, causale, presenza del TRN).
3. Se l'export Versamenti contiene in una riga il collegamento con le transazioni (id) o solo un elenco a parte.
4. Endpoint per scaricare il file della Report API.
5. Rapporto tra `daily_closure` e versamento quando la frequenza non è giornaliera.
6. Trattamento delle commissioni sui rimborsi; condizioni commissionali per APS.

## Fonti (consultate il 30/10/2026)

- [S1] Satispay Help Center, "Introduzione al Pannello di Controllo" — https://support.satispay.com/en/articles/introduzione-al-pannello-di-controllo
- [S2] Satispay Help Center, "How to download a payout transactions report" — https://support.satispay.com/en/articles/how-to-to-download-a-payout-transactions-report
- [S3] Satispay Help Center, "Time required to credit bank account" — https://support.satispay.com/en/articles/time-required-to-credit-bank-account
- [S4] Satispay Developers, "Create new report" (Merchant Reports) — https://developers.satispay.com/reference/create-new-report
- [S5] Satispay Developers, "Get shop payments" — https://developers.satispay.com/reference/get-list-of-payments
- [S6] Satispay Developers, "Get payment" — https://developers.satispay.com/reference/get-the-details-of-a-payment
- [S7] Satispay Developers, indice della documentazione — https://developers.satispay.com/llms.txt
- [S8] Satispay, pagina Business (tariffe) — https://www.satispay.com/it-it/business/
