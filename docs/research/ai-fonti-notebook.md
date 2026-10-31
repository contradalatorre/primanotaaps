# Fondare i suggerimenti AI sulle fonti dei notebook

Ricerca per il ticket #9 (mappa #1). Data: 30 ottobre 2026.

**Domanda.** Come può l'app, ospitata su hosting condiviso Aruba, chiedere a Claude un suggerimento di **Categoria** per un **Movimento** appoggiandosi alle stesse fonti dei due notebook NotebookLM ("Guida alla Contabilità e Rendicontazione degli Enti Terzo Settore" e "Linee guida per la raccolta fondi")?

Opzioni valutate:

- **(a)** un'API di NotebookLM (o NotebookLM Enterprise su Google Cloud);
- **(b)** passare a Claude i documenti sorgente dei notebook (Files API, citazioni, prompt caching);
- **(c)** un manuale di classificazione ricavato una volta dai notebook e incluso nel prompt.

**In breve.** (a) non è praticabile: l'API Enterprise documentata non ha un metodo per interrogare un notebook, richiede almeno 15 licenze e il NotebookLM personale non ha un'API ufficiale. (b) funziona, ma costa da 10 a 100 volte più di (c) per ogni suggerimento, è lenta quando la cache è fredda e metà delle fonti non si può riutilizzare. **Si raccomanda (c)**: un manuale breve, scritto da noi, nel system prompt (con cache), output strutturato vincolato all'elenco delle categorie, nomi dei soci sostituiti con segnaposto prima dell'invio.

---

## 1. Le fonti dei due notebook

Elenco ottenuto con `source_list` (MCP NotebookLM) il 30/10/2026. Il contenuto delle fonti **non** è stato copiato. La colonna "Riutilizzo" indica se la fonte si può passare a un'API o citare nel manuale:

- **Ufficiale**: atto normativo o di prassi di un'autorità pubblica. Gli atti ufficiali dello Stato sono esclusi dal diritto d'autore (L. 633/1941, art. 5).
- **Pubblico con diritti**: pagina o documento liberamente consultabile, ma di un editore o di un ordine professionale. Si cita con un link, non si ripubblica né si carica nel repo.
- **Privato**: materiale di corsi, trascrizioni o documenti interni. Non va né riutilizzato né pubblicato.

Per i PDF caricati senza URL, l'editore è **dedotto dal titolo** e va verificato.

### 1.1 "Guida alla Contabilità e Rendicontazione degli Enti Terzo Settore" (33 fonti)

| # | Titolo | Tipo | Riutilizzo |
|---|---|---|---|
| 1 | 20170802_179_SO_043.pdf (G.U. n. 179 del 2/8/2017, S.O. 43: presumibilmente D.Lgs. 117/2017, Codice del Terzo settore) | pdf | Ufficiale |
| 2 | 2023_04_Il-bilancio-degli-enti-del-Terzo-settore.pdf | pdf | Pubblico con diritti (editore da verificare) |
| 3 | 20260420_trascrizione.txt | testo incollato | Privato (trascrizione di un incontro) |
| 4 | 20260427_trascrizione.txt | testo incollato | Privato |
| 5 | 20261021_webinar_inp_test_commercialita.txt | markdown | Privato (trascrizione di un webinar) |
| 6 | Circolare del 19/02/2026 n. 1 – Agenzia delle Entrate (def.finanze.it) | pagina web | Ufficiale |
| 7 | CTS: chiarimenti sull'utilizzo del rendiconto per cassa in forma aggregata – Ministero del Lavoro (lavoro.gov.it) | pagina web | Ufficiale |
| 8 | Contabilità – Cantiere Terzo Settore | pagina web | Pubblico con diritti |
| 9–10 | Controlli sugli ETS, adottati i modelli di verbale – Cantiere Terzo Settore (**duplicata**) | pagina web | Pubblico con diritti |
| 11 | DI-107-del-19052021-art6-CTS-attivita-diverse-ETS.pdf (D.I. 107/2021, attività diverse) | pdf | Ufficiale |
| 12 | Fiscalità Terzo settore: online la circolare dell'AdE – Cantiere Terzo Settore | pagina web | Pubblico con diritti |
| 13 | Il rendiconto per cassa degli ETS – CNDCEC (commercialisti.it) | pagina web | Pubblico con diritti |
| 14 | Il Terzo settore alla prova dei controlli – Fondazione Terzjus | pagina web | Pubblico con diritti |
| 15 | La relazione dell'organo di controllo degli ETS all'assemblea – CNDCEC | pagina web | Pubblico con diritti |
| 16 | La riforma fiscale per il Terzo settore dal 1° gennaio 2026 – Cantiere Terzo Settore | pagina web | Pubblico con diritti |
| 17 | Norme di comportamento dell'organo di controllo degli ETS – CNDCEC | pagina web | Pubblico con diritti |
| 18 | Runts, controlli e semplificazioni: le risposte del Ministero – CSV Lombardia | pagina web | Pubblico con diritti |
| 19 | Statuto_Contrada_La_Torre_aps_ets | Google Docs (Drive) | Privato/interno: utile per capire le attività statutarie, ma fuori dal repo |
| 20 | Terzo settore, ok Ue al nuovo fisco – Fondazione Terzjus | pagina web | Pubblico con diritti |
| 21 | Test di (non) commercialità ETS 2026 – tuttononprofit.com | pagina web | Pubblico con diritti |
| 22 | Un nuovo rendiconto più semplice per i piccoli ETS – Cantiere Terzo Settore | pagina web | Pubblico con diritti |
| 23 | Vademecum rendiconto per cassa – Cantiere Terzo Settore | pdf (da URL) | Pubblico con diritti |
| 24 | _Circolare_Terzo_Settore n. 1 del 19 febbraio 2026.pdf (doppione PDF della n. 6) | pdf | Ufficiale |
| 25 | slides_relatore_odcec_22_01_2026_cts.pdf | pdf | Privato (slide di un relatore) |
| 26 | dd198_20260810.pdf (decreto direttoriale 198/2026, presumibilmente del Ministero del Lavoro) | pdf | Ufficiale (da verificare) |
| 27 | ets-esercizio-attivita-esclusivamente-o-principalmente-forma-di-impresa-commerciale.pdf | pdf | Da verificare (probabile prassi ministeriale) |
| 28–30 | slides incontro 20 aprile / 27 aprile / 4 maggio tesorieri 1–3 | pdf | Privato (materiale di corso) |
| 31 | verbale_controllo_ordinario_runts.pdf | pdf | Ufficiale (modello ministeriale) |
| 32 | verbale_controllo_straordinario_runts.pdf | pdf | Ufficiale (modello ministeriale) |
| 33 | webinar-22.4.2026-AdE-trascrizione.pdf | pdf | Privato (trascrizione di terzi) |

### 1.2 "Linee guida per la raccolta fondi" (10 fonti)

| # | Titolo | Tipo | Riutilizzo |
|---|---|---|---|
| 1 | 7.-Donazioni-e-raccolte-fondi-completo.pdf | pdf | Pubblico con diritti (editore da verificare) |
| 2 | Codice del Terzo settore – Fondazione Terzjus (Bobba.pdf) | pdf (da URL) | Pubblico con diritti (edizione commentata) |
| 3 | Eventi di raccolta fondi, le indicazioni da seguire – retisolidali.it | pagina web | Pubblico con diritti |
| 4 | Il Codice del Terzo settore – Fimiv | pdf (da URL) | Testo di legge ufficiale, ma nell'impaginazione di un terzo: meglio la fonte G.U./Normattiva |
| 5 | La nuova fiscalità degli ETS dal 1° gennaio 2026 – ODCEC Arezzo | pdf (da URL) | Pubblico con diritti (slide) |
| 6 | La riforma fiscale per il Terzo settore dal 1° gennaio 2026 – Cantiere Terzo Settore (anche nel notebook 1) | pagina web | Pubblico con diritti |
| 7 | Le-linee-guida-sulla-raccolta-fondi-degli-enti-del-Terzo-settore-spiegate.pdf | pdf | Pubblico con diritti (editore da verificare) |
| 8 | Linee-guida-raccolta-fondi-ETS.pdf (presumibilmente il D.M. 9/6/2022) | pdf | Ufficiale |
| 9 | ODV, APS e ONLUS: facciamo chiarezza – Passione Non Profit | pagina web | Pubblico con diritti |
| 10 | _Circolare_Terzo_Settore n. 1 del 19 febbraio 2026.pdf (anche nel notebook 1) | pdf | Ufficiale |

### 1.3 Cosa se ne ricava

- Le **fonti ufficiali** sono circa 12 tra le due liste, tolti i doppioni: D.Lgs. 117/2017, circolare AdE 1/2026, D.I. 107/2021, D.M. raccolta fondi, D.D. 198/2026 e modelli di verbale, nota ministeriale sul rendiconto aggregato. Si possono passare a un'API e citare senza vincoli.
- Lo **schema del Modello D** (D.M. 5 marzo 2020), cioè la fonte più importante per la classificazione, **non compare come fonte autonoma** in nessuno dei due notebook: si trova solo dentro il vademecum e i documenti CNDCEC. Per (b) e (c) conviene aggiungerlo dalla G.U.
- Circa un terzo delle fonti del notebook 1 (trascrizioni, slide dei corsi tesorieri e di un relatore ODCEC, statuto) è **privato o soggetto a diritti**. Non va caricato su servizi esterni per un uso in produzione, né pubblicato nel repo. È proprio il materiale più "operativo" per decidere le categorie: con (c) il suo contributo entra nel manuale come regole riformulate da noi, non come testo.

---

## 2. Opzione (a): API di NotebookLM

**NotebookLM personale (quello usato oggi).** Google non pubblica un'API ufficiale per il NotebookLM personale. Lo strumento MCP `notebooklm-py` usato per questa ricerca è una libreria non ufficiale che si autentica con la sessione Google dell'utente. Non è adatta a un server di produzione: endpoint non documentati, che possono cambiare senza preavviso, e credenziali personali da tenere sul server.

**NotebookLM Enterprise (ora "Gemini Notebook Enterprise", Google Cloud).**

- L'API documentata è **v1alpha / Preview** (Pre-GA, "as is", con supporto limitato) e copre solo la gestione: `notebooks.create`, `get`, `listRecentlyViewed`, `batchDelete`, `share`, più le pagine su fonti, audio overview e podcast. **Non documenta alcun metodo per fare domande o chattare con un notebook** ([Create and manage notebooks (API)](https://docs.cloud.google.com/agentspace/notebooklm-enterprise/docs/api-notebooks); [Overview](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/overview), che elenca solo le pagine API su notebook, fonti, audio overview e podcast). Una discussione sul forum Google Developers segnala la stessa mancanza ([discuss.google.dev](https://discuss.google.dev/t/notebooklm-enterprise-api-missing-query-chat-endpoint-for-rag-orchestration/366875), fonte non ufficiale).
- **Licenze**: abbonamento con **minimo 15 licenze**, mensile o annuale. Prezzi non pubblicati nella documentazione: compaiono nella console o tramite il commerciale ([Set up licensing](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/set-up-licensing)). Fonti terze indicano circa 9 USD per licenza al mese, dato **non verificato** su fonte Google.
- **Privacy**: i dati restano nel progetto Google Cloud, in multi-regione US o UE, con VPC-SC e CMEK disponibili ([Overview](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/overview)).

| Criterio | Valutazione |
|---|---|
| Costo per suggerimento | Non calcolabile: manca l'endpoint di interrogazione. Costo fisso minimo: 15 licenze × ~9 USD ≈ **135 USD al mese** (stima su fonti terze), cioè circa 1.600 USD l'anno prima di qualsiasi suggerimento. |
| Latenza | Non applicabile. |
| Qualità | Sarebbe la più fedele ai notebook, se esistesse la query. |
| Manutenzione | Progetto GCP, service account e OAuth da Aruba, API alpha soggetta a modifiche, notebook da ricreare nel progetto Enterprise. La migrazione dai notebook personali non risulta documentata. |
| Privacy | Buona sulla carta (dati nel progetto GCP), ma le descrizioni con i nomi dei soci passerebbero comunque a Google. |

**Esito: non praticabile** oggi, per assenza di un'API di interrogazione e per il costo fisso sproporzionato per un'APS.

---

## 3. Opzione (b): i documenti sorgente a Claude (Files API, citazioni, caching)

**Come funzionerebbe.**

1. Si caricano una volta i PDF o testi delle fonti riutilizzabili con la Files API (`POST /v1/files`). Le operazioni sui file sono gratuite e il contenuto si paga come token di input quando lo si usa ([Files API](https://platform.claude.com/docs/en/build-with-claude/files)).
2. Ogni richiesta referenzia i `file_id` come blocchi `document`, con `cache_control` sull'ultimo documento, poi aggiunge il movimento.
3. Opzionale: `citations: {enabled: true}` per avere i passaggi esatti a supporto. `cited_text` non conta nei token di output ([Citations](https://platform.claude.com/docs/en/build-with-claude/citations)). **Le citazioni sono incompatibili con gli output strutturati** (`output_config.format`, errore 400), quindi la categoria va estratta dal testo libero.

**Dimensione del corpus (stima, da misurare con `count_tokens`).** Le fonti ufficiali più il Modello D valgono indicativamente **~200.000 token**; il CTS da solo è una legge lunga, la circolare 1/2026 è corposa. L'intero notebook 1 con slide e trascrizioni supera facilmente **~700.000 token**. Entrambe le dimensioni stanno nel contesto da 1M dei modelli correnti.

**Prezzi usati** (USD per milione di token, [Pricing](https://platform.claude.com/docs/en/about-claude/pricing), consultato il 30/10/2026):

| Modello | Input | Scrittura cache 5 min | Lettura cache | Output |
|---|---|---|---|---|
| Claude Haiku 5.5 (prompt ≤100k) | 0,10 | 0,125 | 0,01 | 0,50 |
| Claude Haiku 5.5 (prompt >100k) | 0,50 | 0,625 | 0,05 | 2,50 |
| Claude Sonnet 5.5 | 2,00 | 2,50 | 0,10 | 10,00 |
| Claude Opus 5.5 | 4,00 | 5,00 | 0,20 | 20,00 |

La cache dura 5 minuti e ogni lettura la rinnova gratuitamente. Il Batch API dimezza i prezzi, ma è asincrono.

**Calcolo per suggerimento.** Ipotesi: corpus P in cache, movimento ~150 token (trascurabile), output ~500 token tra ragionamento e risposta.

- Sonnet 5.5, P = 200k:
  - con cache calda: 200.000 × 0,10/1M = 0,020 + output 500 × 10/1M = 0,005 → **≈ 0,025 USD**;
  - primo della sessione (scrittura cache): 200.000 × 2,50/1M = 0,50 + 0,005 → **≈ 0,505 USD**.
- Sonnet 5.5, P = 700k: cache calda 0,070 + 0,005 → **≈ 0,075 USD**; primo 1,75 + 0,005 → **≈ 1,755 USD**.
- Haiku 5.5, P = 200k (fascia >100k): cache calda 200.000 × 0,05/1M = 0,010 + 500 × 2,50/1M = 0,00125 → **≈ 0,011 USD**; primo 0,125 + 0,001 → **≈ 0,126 USD**.
- Opus 5.5, P = 200k: cache calda 0,040 + 0,010 → **≈ 0,050 USD**; primo 1,00 + 0,01 → **≈ 1,01 USD**.

Esempio di sessione: il tesoriere classifica 100 movimenti senza pause oltre i 5 minuti, con Sonnet e P = 200k. Costo: 0,505 + 99 × 0,025 ≈ **2,98 USD, cioè ~0,03 USD a suggerimento**. Ogni pausa più lunga di 5 minuti costa un'altra scrittura da 0,50 USD. In alternativa si possono raggruppare tutti i movimenti di un estratto conto in una sola richiesta.

| Criterio | Valutazione |
|---|---|
| Costo | 0,01–0,15 USD con cache calda, 0,13–1,75 USD a freddo, secondo modello e corpus (calcoli sopra). Da 10 a 100 volte (c). |
| Latenza | Stima, da misurare: a freddo il modello deve elaborare centinaia di migliaia di token, quindi decine di secondi. È un rischio concreto con i limiti di tempo di esecuzione PHP di un hosting condiviso. Con cache calda è molto più rapida, ma resta più lenta di (c). |
| Qualità | Alta sulle domande normative, e le citazioni permettono di verificare. Per scegliere fra **le nostre categorie interne** però le leggi non bastano: serve sapere come l'associazione mappa le sue situazioni (tesseramento, sagre, sponsor) sulle categorie, e questo i documenti normativi non lo dicono. Un corpus enorme aggiunge anche rumore. |
| Manutenzione | Ricaricare i file quando cambiano le fonti, tenere l'elenco dei `file_id` lato server, misurare il corpus. Restano fuori le fonti private e quelle con diritti: si perde proprio il materiale più operativo. |
| Privacy | Vedi §5. In più i file caricati **restano fino a cancellazione o scadenza** (`expires_in_seconds` da 1 ora a 90 giorni). Non sono coperti dalla ZDR e sono accessibili a tutto il workspace ([Files API](https://platform.claude.com/docs/en/build-with-claude/files)). Va bene per fonti pubbliche, non per lo statuto o per trascrizioni con nomi. |

---

## 4. Opzione (c): manuale di classificazione nel prompt

**Come funzionerebbe.**

1. Si usano i notebook (umano più NotebookLM) **una volta** per scrivere un manuale breve, ~5–10k token, con parole nostre:
   - per ogni Categoria: Voce Modello D, definizione, esempi di Descrizione tipici, casi limite, errori comuni (per esempio un giroconto scambiato per entrata);
   - riferimenti alle fonti solo come citazione: "CTS art. 79", "Circ. AdE 1/2026 §…".
2. Il manuale va nel **system prompt con `cache_control`**. Ogni richiesta aggiunge solo il movimento: Descrizione con nomi pseudonimizzati, importo con segno, Conto, data.
3. Si usa l'**output strutturato** (`output_config.format`) con `categoria` come enum delle categorie, più `confidenza` e `regola_applicata`. Così la risposta è sempre una categoria valida. Si rinuncia alle citazioni API (incompatibili con l'output strutturato) a favore del campo `regola_applicata`, che rimanda alla voce del manuale.

**Calcolo per suggerimento.** Ipotesi: manuale M = 8.000 token in cache, movimento 150 token, output 500 token.

- Haiku 5.5:
  - cache calda: 8.000 × 0,01/1M = 0,00008 + 150 × 0,10/1M = 0,000015 + 500 × 0,50/1M = 0,00025 → **≈ 0,0003 USD**;
  - senza cache: 8.000 × 0,10/1M = 0,0008 + 0,00025 → **≈ 0,001 USD**.
- Sonnet 5.5:
  - cache calda: 8.000 × 0,10/1M = 0,0008 + 150 × 2/1M = 0,0003 + 500 × 10/1M = 0,005 → **≈ 0,006 USD**;
  - senza cache: 8.000 × 2/1M = 0,016 + 0,0003 + 0,005 → **≈ 0,021 USD**.
- Opus 5.5: cache calda 0,0016 + 0,0006 + 0,010 → **≈ 0,012 USD**; senza cache 0,032 + 0,0006 + 0,010 → **≈ 0,043 USD**.
- Su base annua, con ~1.000 movimenti all'anno (ipotesi per una piccola APS): Sonnet 5.5 costa **6–21 USD l'anno**, Haiku 5.5 meno di **1 USD l'anno**.

Il manuale supera di molto le soglie minime di caching del modello: la skill `claude-api` indica 512 token sui modelli più recenti e 4.096 al massimo sui modelli più vecchi. Il valore va ricontrollato sulla pagina ufficiale [Prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching) prima di contarci.

| Criterio | Valutazione |
|---|---|
| Costo | 0,0003–0,04 USD a suggerimento (calcoli sopra). Il più basso. |
| Latenza | Prompt piccolo: tempo dominato dall'output e dal ragionamento, tipicamente pochi secondi (stima da misurare; si può tenere `effort: "low"`). Compatibile con una richiesta sincrona da PHP. |
| Qualità | Buona per il compito reale, cioè scegliere fra le **nostre** categorie, perché il manuale codifica proprio la corrispondenza tra situazioni dell'associazione, Categorie e Voci Modello D. Limiti: non sa rispondere a casi fuori manuale, e un manuale sbagliato produce errori sistematici. Va tarato su un set di movimenti di prova anonimizzati: confronto suggerimento/scelta umana, poi si aggiorna il manuale. |
| Manutenzione | Un file versionato nel repo, da aggiornare quando cambiano le categorie o la normativa: una revisione annuale all'apertura dell'Esercizio. Non richiede né Google Cloud né la Files API. |
| Privacy | Vedi §5. Ad Anthropic arriva solo il manuale (nostro, pubblicabile) più il singolo movimento: superficie minima. |

**Sul repo pubblico.** Il manuale è testo originale con soli riferimenti normativi, quindi può stare nel repo (per esempio `docs/manuale-classificazione.md`). **Non** deve contenere esempi presi da movimenti reali né nomi: gli esempi vanno inventati.

---

## 5. Privacy: cosa conserva l'API di Claude

Le Descrizioni possono contenere nomi di soci, per esempio un bonifico con causale "quota 2026 Mario Rossi". Ecco cosa trattiene Anthropic per l'API commerciale (non per i prodotti consumer):

- **Input e output dei Messaggi**: cancellati dal backend **entro 30 giorni** dalla ricezione o generazione, salvo accordi diversi come la ZDR, obblighi di legge o violazioni delle Usage Policy ([Privacy Center: How long do you store my organization's data](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data)).
- **Contenuti segnalati** dai sistemi trust & safety: fino a **2 anni**; punteggi di classificazione fino a 7 anni (stessa fonte).
- **Addestramento**: i dati conservati "non sono mai usati per l'addestramento dei modelli senza il vostro esplicito permesso" ([API and data retention](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention)).
- **ZDR** (zero data retention): disponibile su richiesta al commerciale, attivata per organizzazione. Con la ZDR prompt e risposte non sono conservati dopo la risposta (stessa fonte). Per un'APS è improbabile, ma è l'opzione massima.
- **Prompt caching**: prompt e output non vengono salvati; le rappresentazioni KV e gli hash restano **in memoria per la durata del TTL** e poi vengono cancellati. Il caching è compatibile con la ZDR (stessa fonte, tabella feature).
- **Output strutturati**: viene messo in cache solo lo **schema JSON, fino a 24 ore**. Lo schema non deve quindi contenere dati personali: l'enum delle categorie va bene, i nomi no.
- **Files API**: i file restano **fino a cancellazione o scadenza** e non sono idonei alla ZDR ([Files API](https://platform.claude.com/docs/en/build-with-claude/files)).
- **Covered Models** (Claude Fable 5/5.1, Mythos): richiedono **30 giorni di conservazione obbligatoria**. Da evitare per questo uso, anche perché costano molto di più.

**Mitigazioni consigliate, valide per qualunque opzione:**

1. **Minimizzare**: inviare solo Descrizione, importo con segno, Conto e data. Mai IBAN completi, codici fiscali o anagrafiche.
2. **Pseudonimizzare** lato server prima dell'invio: sostituire i nomi presenti nell'anagrafica soci con `[SOCIO]`, e mascherare IBAN e codici con regex. Il nome non serve a scegliere la categoria; serve la parola "quota".
3. Non registrare nei log dell'app i prompt completi con dati non pseudonimizzati.
4. Chiave API in un file di configurazione fuori dalla document root di Aruba, workspace dedicato con limite di spesa.
5. Indicare nell'informativa privacy dell'associazione che Anthropic è un responsabile del trattamento (DPA commerciale).

---

## 6. Confronto

| | (a) NotebookLM API | (b) Fonti a Claude | (c) Manuale nel prompt |
|---|---|---|---|
| Fattibilità | **No** (manca l'endpoint di interrogazione; API alpha) | Sì | Sì |
| Costo per suggerimento | n.d.; fisso ≥ ~135 USD/mese | 0,01–0,15 USD con cache calda; 0,13–1,75 USD a freddo | **0,0003–0,04 USD** |
| Latenza | n.d. | Alta a freddo (centinaia di migliaia di token) | **Bassa** |
| Qualità sulle nostre categorie | n.d. | Buona sulle norme, debole sulla mappatura interna | **Buona**, se il manuale è tarato |
| Manutenzione | Alta (GCP, alpha) | Media (file, ID, corpus filtrato per diritti) | **Bassa** (un file nel repo) |
| Privacy | Dati a Google | Descrizioni 30 gg più file persistenti | **Descrizioni 30 gg, superficie minima** |
| Adatta ad Aruba condiviso | No | A rischio timeout | **Sì**: una chiamata HTTPS breve |

---

## 7. Raccomandazione

**Adottare l'opzione (c).**

1. **Scrivere un manuale di classificazione** (~5–10k token) usando i due notebook come strumento di studio, con parole nostre e soli riferimenti normativi. Va versionato nel repo, con esempi inventati. Il ticket #15, bloccato da questo, può partire da qui.
2. **Una chiamata Messages API per suggerimento** (o una per estratto conto, con più movimenti in un array):
   - manuale nel `system` con `cache_control`;
   - `output_config.format` con enum delle categorie, confidenza e regola applicata;
   - effort basso.
   Si fa con l'SDK PHP ufficiale di Anthropic o con una richiesta HTTPS da PHP. Va verificato che l'hosting Aruba consenta chiamate HTTPS in uscita (cURL) e che il tempo di esecuzione PHP basti, anche se con (c) la richiesta è breve.
3. **Modello**: partire da **Claude Sonnet 5.5** (~0,006 USD con cache calda, decine di USD l'anno al massimo). Misurare poi **Claude Haiku 5.5** su un set di prova anonimizzato: se l'accuratezza tiene, il costo scende sotto 1 USD l'anno. Evitare i Covered Models.
4. **Privacy**: pseudonimizzare i nomi dei soci e mascherare gli identificativi prima dell'invio. Non usare la Files API per dati dell'associazione.
5. **(b) solo come eventuale evoluzione** per un pulsante "approfondisci" sui casi dubbi. In quel caso userebbe solo le fonti ufficiali, con citazioni attive, Batch API o cache, e accetterebbe la latenza. Non serve per l'MVP.
6. **Scartare (a)** finché Google non pubblica un endpoint di interrogazione per NotebookLM Enterprise. Da ricontrollare in futuro: l'API è alpha.

**Punti aperti da verificare:**

- dimensione reale in token del corpus (`count_tokens`), se mai si valutasse (b);
- editore effettivo dei PDF caricati senza URL;
- latenza reale da Aruba;
- accuratezza Haiku contro Sonnet su un campione anonimizzato.

---

## Fonti

- Anthropic, [Files API](https://platform.claude.com/docs/en/build-with-claude/files): tipi supportati, conservazione, operazioni gratuite, ZDR non idonea, scadenza.
- Anthropic, [Citations](https://platform.claude.com/docs/en/build-with-claude/citations): costi in token, incompatibilità con output strutturati, uso con il caching.
- Anthropic, [Prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching) e [Pricing](https://platform.claude.com/docs/en/about-claude/pricing): prezzi dei modelli 5.5, moltiplicatori cache, fascia >100k di Haiku 5.5, sconto Batch.
- Anthropic, [API and data retention](https://platform.claude.com/docs/en/manage-claude/api-and-data-retention): ZDR, caching, output strutturati, Covered Models, addestramento.
- Anthropic Privacy Center, [How long do you store my organization's data](https://privacy.claude.com/en/articles/7996866-how-long-do-you-store-my-organization-s-data): 30 giorni, 2 anni per i contenuti segnalati.
- Skill `claude-api` (cache del 06/10/2026): elenco modelli correnti e prezzi, verificati sulla pagina Pricing.
- Google Cloud, [Create and manage notebooks (API)](https://docs.cloud.google.com/agentspace/notebooklm-enterprise/docs/api-notebooks): metodi v1alpha, stato Preview.
- Google Cloud, [Gemini Notebook Enterprise overview](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/overview): residenza dei dati, VPC-SC, CMEK, pagine API.
- Google Cloud, [Set up licensing](https://docs.cloud.google.com/gemini/enterprise/notebooklm-enterprise/docs/set-up-licensing): minimo 15 licenze, prova di 14 giorni.
- Non ufficiali, segnalate come tali: [discuss.google.dev](https://discuss.google.dev/t/notebooklm-enterprise-api-missing-query-chat-endpoint-for-rag-orchestration/366875) (assenza dell'endpoint di interrogazione); siti terzi per il prezzo di ~9 USD/licenza/mese, non confermato da Google.
- Elenco delle fonti dei notebook: strumento MCP NotebookLM `source_list`, 30/10/2026.
