# Formato dell'estratto conto Crédit Agricole (Nowbanking)

Ricerca per il ticket #3 della mappa "Webapp prima nota APS" (#1).
Fonti consultate il **30/10/2026**. Ogni affermazione indica la fonte da cui viene; ciò che non è stato
possibile verificare è segnalato nella sezione "Da verificare con estratti di esempio (ticket #11)".

Tutti gli esempi di righe in questo documento sono **inventati** oppure presi da file di test pubblici
già anonimizzati; non contengono dati dell'associazione.

## Sintesi

1. Il conto dell'associazione usa con ogni probabilità l'**Home Banking versione Piccole Imprese**
   ("Servizi digitali Crédit Agricole – Piccole Imprese", monobanca), incluso gratis nel Conto
   Associazioni No Profit [1][2]. La documentazione ufficiale elenca "saldo e movimenti" tra le funzioni
   informative ma **non documenta pubblicamente i formati di esportazione** dell'elenco movimenti [2][3].
2. Dall'home banking si possono ottenere, secondo fonti di terzi verificate sul codice, due tracciati
   tabellari dell'elenco movimenti: un **XLS "Lista Movimenti Conto"** con intestazione e totali, e un
   **CSV piatto** separato da punto e virgola [4][5]. Gli estratti conto ufficiali (documenti di
   trasparenza) sono scaricabili in **PDF ed Excel** [6].
3. In nessuno dei formati tabellari esiste un **identificativo univoco del movimento**: la
   deduplicazione dovrà basarsi su una chiave composta (data operazione, data valuta, importo,
   descrizione, più un contatore per le righe identiche).
4. I formati **CBI** (RH a record fissi, rendicontazione XML) e **camt.053** sono disponibili solo con
   **Nowbanking Corporate**, a pagamento (da 20 €/mese + IVA) [7]: non utilizzabili a costo zero.
5. **API PSD2 a costo zero: in pratica no.** Crédit Agricole Italia espone le API PSD2 tramite CBI Globe
   [8], ma vi accedono solo TPP autorizzati (AISP). L'unico aggregatore trovato con un piano gratuito
   (Enable Banking) lo limita a uso personale di privati o a valutazione, ed **esclude esplicitamente i
   conti di imprese/organizzazioni** [9]; GoCardless Bank Account Data non accetta nuove iscrizioni da
   luglio 2025 [10]. Raccomandazione: **import da file (CSV/XLS) caricato a mano**.

## Quale home banking usa l'associazione

- Il **Conto Associazioni No Profit** include gratuitamente il "canone del Servizio di Home Banking
  versione Piccole Imprese, il servizio di internet banking monobanca" [1].
- Il foglio informativo 25.008 "Servizi digitali Crédit Agricole – Piccole Imprese" lo descrive come
  servizio "riservato alla Clientela che opera esclusivamente in modalità monobanca"; tra le funzioni
  informative cita "Situazione conti correnti e carte (saldo e movimenti)". Canone di listino 84 €/anno
  (azzerato nel conto No Profit) [2][1].
- Le istruzioni operative (versione Piccole Imprese, luglio 2024) confermano l'accesso da
  `nowbanking.credit-agricole.it` e dall'app; tra i servizi: "Saldo e lista movimenti conti correnti e
  carta di debito"; nell'area riservata (Ecodoc) vengono pubblicati estratti conto, documenti di sintesi,
  contabili ecc. Non viene nominato alcun formato di esportazione [3].
- La stringa "CANONE NOwbanking" nelle descrizioni del foglio 2025 è coerente con questo servizio
  (nome commerciale Nowbanking), ma quale versione sia attiva sul conto va confermato (ticket #11).

## Formato 1 – XLS "Lista Movimenti Conto" (export dall'home banking)

Fonte: estensione di import ufficiale di Banana Contabilità per Crédit Agricole Italia
(`ch.banana.italia.import.creditagricole`, pubblicata 13/05/2026) e il suo file di test
`xls_creditagricole_example_format1_20241230.csv` (un XLS convertito in CSV, con dati anonimizzati) [4][5].
La pagina Banana indica il percorso "sezione conti → selezionare il conto → esportare i movimenti
scegliendo il formato csv oppure xlsx/xls" [4].

Struttura osservata nel file di test:

- **Blocco di intestazione** (righe 1–11, prima della tabella): titolo "Lista Movimenti Conto";
  "Tipo movimento: Tutti"; "Numero Conto"; "Data Aggiornamento"; "Intestatario"; **"Saldo Iniziale"** e
  **"Saldo Finale"** (testo del tipo `"627,89 EUR"`, virgola decimale + codice valuta); "Data dal" /
  "Data al" (gg/mm/aaaa); filtri "Importo da/a", "Causale", "Periodo". Una riga vuota.
- **Riga 12 – intestazioni di colonna**: `Data Op.`, `Data Val.`, `Causale`, `Descrizione`, `Importo`, `Div.`
- **Righe dati** da riga 13, in **ordine di data decrescente** (il più recente in alto).
- **Righe di chiusura**: una riga vuota, poi `Totale Entrate €`, `Totale Uscite €`, `Totale Movimenti €`.

Campi:

| Colonna | Contenuto | Note |
|---|---|---|
| Data Op. | data operazione, `gg/mm/aaaa` | coerente con l'indizio del foglio 2025 |
| Data Val. | data valuta, `gg/mm/aaaa` | può differire dalla data operazione |
| Causale | categoria bancaria breve (es. `GIROCONTO/BONIFICO`, `PAGAMENTO RATE FINANZIAMENTO`) | riempita con spazi a destra fino a larghezza fissa (~35 caratteri) |
| Descrizione | testo libero lungo della banca | può contenere ordinante/beneficiario, riferimenti, codici carta; spazi finali di riempimento |
| Importo | importo **con segno**: negativo = uscita, positivo = entrata | nel file di test (passato per conversione) il decimale è il punto; nell'XLS originale è probabilmente un numero nativo di Excel (da verificare) |
| Div. | valuta (`EUR`) | |

- **Segno**: colonna unica con segno (nessuna coppia Dare/Avere) [5].
- **Saldo**: solo saldo iniziale e finale del periodo nell'intestazione, nessun saldo progressivo per riga.
- **Codifica**: il file di test è UTF-8 con BOM, ma è già una conversione; la codifica dell'XLS è
  irrilevante (binario Excel).
- **Identificativo univoco**: assente.

Esempio inventato di righe (stessa struttura):

```
Data Op.,Data Val.,Causale,Descrizione,Importo,Div.
14/03/2026,14/03/2026,GIROCONTO/BONIFICO                 ,ORD: MARIO ESEMPIO DT.ORD 13/03/2026 DESCR.OPERAZIONE SCT QUOTA ASSOCIATIVA 2026,25.00,EUR
10/03/2026,09/03/2026,PAGAMENTO                          ,CARTA CA DEBIT VISA *1234 FERRAMENTA ESEMPIO,-42.90,EUR
```

## Formato 2 – CSV piatto

Fonte: stessa estensione Banana, file di test `csv_creditagricole_example_format2_20260415.csv`
(aggiunto a maggio 2026) [4][5].

- **Nessun blocco di intestazione**: la prima riga è direttamente l'intestazione di colonna.
- **Separatore**: punto e virgola `;`; nessun delimitatore di testo nel campione.
- **Colonne**: `Data Op.;Data Val.;Causale;Descrizione;Importo;Divisa` (nota: qui `Divisa`, nell'XLS `Div.`).
- **Date**: `gg/mm/aaaa`.
- **Importi**: virgola decimale (`530,91`); gli importi **negativi sono preceduti da un apostrofo**
  (`'-200,00`), artificio per impedire a Excel di interpretarli come formule. Il parser dovrà togliere
  l'apostrofo. Separatore delle migliaia non osservato nel campione (da verificare con importi ≥ 1.000).
- **Segno**: negativo = uscita, positivo = entrata.
- **Ordine**: data decrescente.
- **Saldo**: assente.
- **Codifica**: campione in solo ASCII e senza BOM; con caratteri accentati potrebbe essere UTF-8 o
  Windows-1252 (da verificare).
- **Identificativo univoco**: assente.

Esempio inventato:

```
Data Op.;Data Val.;Causale;Descrizione;Importo;Divisa
31/03/2026;31/03/2026;INTERESSI/COMPETENZE;CANONE NOwbanking;'-7,00;EUR
20/03/2026;20/03/2026;PAGAMENTO;SDD A : FORNITORE ENERGIA ESEMPIO;'-58,30;EUR
14/03/2026;14/03/2026;GIROCONTO/BONIFICO;ORD: MARIO ESEMPIO DT.ORD 13/03/2026 DESCR.OPERAZIONE SCT QUOTA 2026;25,00;EUR
```

Osservazione: la combinazione "Causale" + "Descrizione" corrisponde a ciò che nel glossario è la
**Descrizione** del movimento; la webapp può concatenarle (come fa Banana) o conservarle separate.

## Formato 3 – Estratto conto ufficiale (PDF / Excel, Ecodoc)

- Il portale **Rendicontazione Online** (per chi non ha l'home banking) offre estratti conto e documenti
  di sintesi "scaricabili in formato PDF ed excel"; chi ha i Servizi Digitali trova gli stessi documenti
  nella sezione **Ecodoc** dell'internet banking (pagina del 25/11/2022) [6].
- Il PDF è il documento ufficiale con saldo iniziale e finale del periodo (trimestrale/periodico); è
  adatto a quadrare i saldi, non come fonte primaria di import (va estratto il testo, layout non
  documentato).
- Struttura della versione Excel dell'estratto conto: **non verificata** (non trovata documentazione).

## Formato 4 – CBI (RH a record fissi, rendicontazione XML) e camt.053

- Il CBI prevede la funzione "Saldi e movimenti Euro e altre divise" (**RH**, tracciato a record fissi,
  manuale CBI-RND-001) e le funzioni "Rendicontazione XML Giornaliera / Periodica / Intraday"
  (messaggi `CBIDlyStmtReqLogMsg`, `CBIPrdcStmtReqLogMsg`, `CBIIdyStmtReqLogMsg`, manuale STRN-MO-001) [11].
- Da Crédit Agricole Italia questi flussi passano dal servizio **Nowbanking Corporate**, rivolto ad
  "Aziende, Professionisti e Condomini", versione monobanca o multibanca CBI. Il canone parte da
  **20 €/mese + IVA** (pacchetto Light monobanca); l'estrazione dati da conto corrente è nel "Modulo
  Dispositivo Avanzato" (**+10 €/mese**) [7].
- I tracciati CBI-RND-001 e STRN-MO-001 **non sono pubblici** (si ottengono tramite banca o consorzio
  CBI). Per conoscenza generale, non verificata su fonte primaria: il flusso RH è a record di 120
  caratteri (testa RH, record 61 saldo iniziale, 62 movimento con data contabile/valuta, segno D/C,
  importo, causale ABI, 63 informazioni aggiuntive, 64 saldo finale, coda EF) e la rendicontazione XML
  CBI è derivata da ISO 20022 camt.053.
- **Conclusione**: costo ricorrente e servizio pensato per aziende → fuori portata per l'obiettivo
  "costo zero". Nessuna evidenza che l'home banking Piccole Imprese offra camt.053, OFX o QIF.

## Accesso API (PSD2 / open banking)

- Crédit Agricole Italia (ABI 6230) aderisce a **CBI Globe**, il gateway PSD2 del consorzio CBI [8].
  CBI Globe verifica che il chiamante sia un TPP presente nel registro EBA con ruolo AISP/PISP/CISP [8]:
  **un'associazione non può chiamare direttamente l'API** senza essere un prestatore autorizzato.
- La strada praticabile sarebbe un aggregatore autorizzato come AISP:
  - **Enable Banking** supporta "Crédit Agricole Cariparma (Crédit Agricole Italia)" via CBI Globe, con
    redirect e SCA biometrica nell'app Nowbanking; per lo storico oltre 90 giorni richiede un secondo
    consenso SCA; le carte di credito senza IBAN non sono esposte [12]. I Termini (aggiornati al
    09/01/2026) dicono che l'uso è gratuito, ma "Production use is limited to Linked Accounts …
    solely for evaluation purposes or for the personal use of private individuals" e che non danno
    diritto di accedere a "account information belonging to businesses"; l'uso da parte di soggetti
    business è tollerato solo come valutazione, revocabile con preavviso [9]. Un conto intestato a
    un'APS **non rientra** nell'uso gratuito consentito.
  - **GoCardless Bank Account Data** (ex Nordigen, che aveva un piano gratuito) non accetta nuovi
    account da luglio 2025 (documentazione di Actual Budget) [10].
- Il consenso PSD2 AIS va comunque rinnovato periodicamente con SCA (durata per Crédit Agricole non
  documentata nelle fonti consultate) e molte banche italiane ammettono un solo consenso attivo per
  TPP [12]: anche tecnicamente l'automazione non sarebbe "a mano zero".
- **Risposta**: non esiste un accesso API PSD2 utilizzabile da un'associazione a costo zero in modo
  conforme ai termini. Per la webapp conviene l'**import manuale del file CSV/XLS** esportato da
  Nowbanking.

## Implicazioni per la webapp

- Parser per due varianti: XLS con blocco d'intestazione (cercare la riga che inizia con `Data Op.`,
  fermarsi alla prima riga vuota / `Totale …`) e CSV `;` con apostrofo davanti ai negativi.
- Riconoscere l'intestazione per nome di colonna, non per posizione (le due varianti differiscono:
  `Div.` vs `Divisa`).
- Date `gg/mm/aaaa`; usare la **Data Op.** come data del movimento (coerente con il foglio 2025),
  conservare la data valuta.
- Rimuovere gli spazi di riempimento da Causale e Descrizione.
- Deduplicazione con chiave composta + contatore di occorrenza, perché non c'è un ID univoco e possono
  esistere movimenti identici nello stesso giorno (es. due prelievi uguali).
- Quando disponibile (XLS), usare Saldo Iniziale/Finale per verificare che la somma dei movimenti
  importati quadri.

## Da verificare con estratti di esempio (ticket #11)

Questi punti si vedono solo dall'interno dell'home banking e non sono documentati pubblicamente:

1. Quale versione di home banking è attiva sul conto (Piccole Imprese vs Privati) e quali pulsanti di
   esportazione offre la lista movimenti (CSV, XLS/XLSX, PDF, altro).
2. Se l'XLS reale corrisponde al "Formato 1" (blocco d'intestazione su 11 righe, totali in coda) e se
   l'importo è numerico nativo o testo; se il CSV reale corrisponde al "Formato 2".
3. **Codifica** del CSV (UTF-8 con/senza BOM o Windows-1252) con descrizioni accentate.
4. Separatore delle migliaia negli importi ≥ 1.000 (`1.234,56`?).
5. Periodo massimo esportabile in un solo file e profondità dello storico (serve un anno intero per
   l'esercizio).
6. Se la lista include movimenti "prenotati"/non contabilizzati che poi cambiano (rischio doppioni).
7. Dove compaiono, in Causale o in Descrizione, le stringhe del foglio 2025 ("CANONE NOwbanking",
   "CARTA CA DEBIT VISA", "SDD A :", "ORD: … DT.ORD … DESCR.OPERAZIONE SCT").
8. Struttura della versione Excel dell'estratto conto ufficiale in Ecodoc, se presente.

## Fonti

Tutte consultate il 30/10/2026.

1. Crédit Agricole Italia, "Conto Associazioni No Profit" –
   https://www.credit-agricole.it/professionisti-e-pmi/conti-correnti/conto-associazioni-no-profit
2. Crédit Agricole Italia, Foglio informativo n. 25.008 "Servizi digitali Crédit Agricole – Piccole
   Imprese", aggiornato al 01/01/2025 –
   https://www.credit-agricole.it/media/filer_public/1e/e9/1ee9851d-d0ad-438e-99f4-2ba6760c9faf/fia25008_nowbankpim.pdf
3. Crédit Agricole Italia, "Manuale tecnico Servizi digitali Crédit Agricole – versione Piccole Imprese"
   (Istruzioni operative, PDF del 30/07/2024) – https://www.credit-agricole.it/d/d/filer/canonical/1722329166/29040/ ;
   versione Privati (PDF dell'08/08/2023) –
   https://static.credit-agricole.it/credit-agricole-it/system/caita_core/attachment_file/data/16129/ISTRUZIONI_OPERATIVE_NB_PRIVATI_CA_ITALIA.pdf
4. Banana Contabilità, "Credit Agricole – Importa movimenti .xls (Banana+ Advanced)" –
   https://www.banana.ch/apps/it/node/9905
5. BananaAccounting/Italia su GitHub, estensione e file di test (Apache 2.0) –
   https://github.com/BananaAccounting/Italia/tree/master/ImportExtensions/credit_agricole_bank_statement_xls
   (in particolare `ch.banana.italia.import.creditagricole.js` e `test/testcases/*.csv`)
6. Crédit Agricole Italia, "Rendicontazione Online: documenti di trasparenza in formato digitale"
   (25/11/2022) –
   https://www.credit-agricole.it/informative/rendicontazione-online-documenti-di-trasparenza-in-formato-digitale
7. Crédit Agricole Italia, Foglio informativo n. 25.011 "Nowbanking Corporate", aggiornato al
   13/06/2025 –
   https://www.credit-agricole.it/media/filer_public/14/e3/14e3215d-72b3-4e28-9b00-5393c9b3741b/fia25011_nowbankcor.pdf
8. CBI Globe, FAQ (elenco aderenti con Crédit Agricole Italia, ABI 6230; verifica TPP su registro EBA) –
   https://www.cbiglobe.com/FAQ/L/1
9. Enable Banking, Terms of Service (Last updated: January 9, 2026) – https://enablebanking.com/terms
10. Actual Budget, documentazione "GoCardless" (chiusura nuove iscrizioni da luglio 2025) –
    https://actualbudget.org/docs/advanced/bank-sync/gocardless/
11. Elenco delle Funzioni del Servizio CBI (prospetto CBI del 19/04/2016, ripubblicato da EOS Solutions) –
    https://docs.eos-solutions.it/Images/PR000384-0/CBI_RecordType.pdf
12. Enable Banking, "Open Banking Specifics in Italy" – https://enablebanking.com/docs/markets/it/
