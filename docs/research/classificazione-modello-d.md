# Classificazione dei movimenti nel Modello D

Ricerca per il ticket #8 (mappa wayfinder #1). Domanda: come vanno classificati nel **Modello D** (rendiconto per cassa, DM 5 marzo 2020) i casi ricorrenti di un'APS, e quali **categorie** della tabella Mappatura 2025 sono classificate in modo dubbio.

Data della ricerca: 30 ottobre 2026.

> **Limite delle fonti.** Il testo ufficiale del DM 5 marzo 2020 (GU n. 102 del 18 aprile 2020) **non è stato verificato direttamente**: le pagine di gazzettaufficiale.it non erano leggibili dallo strumento di ricerca. Lo schema del Modello D e i nomi delle voci sono ripresi dalla tabella Mappatura, dalla riproduzione dello schema nelle fonti del notebook ([V], [B]) e da quanto il notebook attribuisce al DM. Le risposte di NotebookLM sono state controllate sui passaggi citati; dove la risposta andava oltre il passaggio citato lo si segnala come **non confermato**.

## Fonti

Notebook NotebookLM "Guida alla Contabilità e Rendicontazione degli Enti Terzo Settore" e "Linee guida per la raccolta fondi". Sigle usate nel testo:

| Sigla | Fonte |
|---|---|
| [V] | *Vademecum rendiconto per cassa*, Cantiere Terzo Settore (CSVnet): schema del Modello D annotato voce per voce |
| [B] | *Il bilancio degli enti del Terzo settore* (2023), cap. 5 "Gli schemi di bilancio per gli Ets piccoli" |
| [CTS] | D.Lgs. 117/2017 (Codice del Terzo settore), testo in GU, S.O. n. 43 del 2 agosto 2017: artt. 6, 7, 17, 79, 82, 85, 86, 87 |
| [AdE] | Agenzia delle Entrate, Circolare n. 1/E del 19 febbraio 2026 (fiscalità del Terzo settore) |
| [WAdE] | Trascrizione del webinar Agenzia delle Entrate del 22 aprile 2026 |
| [LG] | Linee guida sulla raccolta fondi degli ETS (DM Lavoro 9 giugno 2022) e relative schede esplicative ("Le linee guida ... spiegate", "Donazioni e raccolte fondi") |
| [T2] | Slide incontro tesorieri del 27 aprile 2026 (piano dei conti ricondotto al Modello D) |
| [T3] | Slide incontro tesorieri del 4 maggio 2026 (regime fiscale APS, art. 85 CTS) |
| [MLPS] | Nota Ministero del Lavoro n. 10358 del 14 luglio 2022 (saldo iniziale di liquidità nel Mod. D), citata in [B] |

## Principi generali

- **Cassa pura.** Ogni movimento si rileva quando avviene l'incasso o il pagamento [V, "Principio di cassa"].
- **Prima la sezione, poi la riga.** Ogni entrata e uscita va prima attribuita all'attività cui si riferisce (sezione A, B, C, D, E, oppure investimenti) e solo dopo alla riga [V, "Indicazioni di metodo"].
- **Natura commerciale ≠ sezione.** La sezione del Modello D dice *che tipo di attività* è; la natura commerciale ai fini IRES/IVA si decide con le regole fiscali (artt. 79, 85, 86 CTS). Una voce in B è di regola commerciale, ma anche una voce in A o C può esserlo (es. A7 se l'attività di interesse generale supera il test dell'art. 79 c. 2-bis).

## Casi ricorrenti

| # | Caso | Voce Modello D | Commerciale | Fonte |
|---|---|---|---|---|
| 1 | Commissioni PayPal e Satispay | Incasso **al lordo** nella voce di entrata del movimento; commissione come uscita autonoma in **D1 "Su rapporti bancari"** | No (è un costo) | [V] sez. D1; [V] principio di cassa |
| 2 | Giroconti tra cassa, banca, PayPal, Satispay | **Non compaiono** tra entrate e uscite; restano solo nel prospetto finale "Cassa e banca" (Cassa / Depositi bancari e postali) | — | [B] cap. 5; [MLPS] |
| 3a | Quota associativa | **A1 "Entrate da quote associative e apporti dei fondatori"**, anche se pagata col pulsante "donazione" di PayPal: conta la sostanza, non il canale | No (art. 79 c. 6 e art. 85 c. 5 CTS) | [V] voce A1; [CTS] art. 85 c. 5 |
| 3b | Erogazione liberale spontanea | **A4 "Erogazioni liberali"** | No (art. 79 c. 5-bis CTS) | [V] voce A4; [LG] |
| 4a | Contributo da ente pubblico a fondo perduto | **A8 "Contributi da enti pubblici"** | No, se l'attività di interesse generale resta entro costi effettivi + 6% (art. 79 c. 2-bis e c. 4 lett. b CTS) | [AdE] § 2.3.3.1; [T2] |
| 4b | Somme da ente pubblico in rapporto sinallagmatico (convenzione, appalto) | **A9 "Entrate da contratti con enti pubblici"**; se l'attività diventa commerciale, A7 o B3 | Dipende dal test art. 79 c. 2-bis | [AdE] § 2.3.3.1 |
| 4c | Contributo da soggetto privato | **A6 "Contributi da soggetti privati"** | No | [V]; [T2] |
| 5 | 5 per mille | **A5 "Entrate del 5 per mille"** | No | [V]; [T2] |
| 6a | Raccolta fondi abituale (campagne continuative) | **C1** entrate / **C1** uscite | No se senza corrispettivo (sollecitazione, lasciti); sì se con vendita continuativa di beni o servizi | [LG]; [AdE], paragrafo sulle raccolte fondi |
| 6b | Raccolta fondi occasionale (in concomitanza di ricorrenze o celebrazioni, anche con beni di modico valore) | **C2** entrate / **C2** uscite | No (art. 79 c. 4 lett. a CTS). Per ogni raccolta serve un **rendiconto specifico con relazione illustrativa** allegato al Modello D (art. 87 c. 6 CTS) | [LG]; [V] voce C2; [T2] |
| 7 | Sponsorizzazioni e pubblicità | **B3 "Entrate per prestazioni e cessioni a terzi"** (attività diverse) | **Sì**, imponibili; ma escluse dal test di commercialità dell'ente (art. 79 c. 5 CTS) | [AdE], commento all'art. 79 c. 5 sulle sponsorizzazioni; DM 107/2021 (criteri per le attività diverse) |
| 8 | Somministrazione di cibo e bevande alla Festa della Torre (aperta al pubblico) | **C2** se inquadrata come raccolta pubblica occasionale di fondi. Altrimenti **B3** (attività diversa) | C2: no. B3: **sì**. La somministrazione al pubblico non è decommercializzata per le APS (art. 85 c. 3 CTS) | [V] voce C2 (cita espressamente "somministrazione alimenti e bevande"); [CTS] art. 79 c. 4 lett. a, art. 85 c. 3; [T3] |
| 9 | Rimborsi spese documentati ai volontari | Nella sezione dell'attività cui si riferiscono: **A5** (interesse generale), **B5** (attività diverse), **C1/C2** (raccolte fondi). **Mai** in "Personale" | No | [V] voce A5 e C1/C2; [T2] ("Personale" = rapporto di lavoro contrattualizzato); [CTS] art. 17 c. 3-4 |
| 10a | Imposta di bollo sul c/c, IMU, TARI della sede | **A5 "Uscite diverse di gestione"**, o E5 se di supporto generale; IMU su immobili da reddito in D3 | No | [V] voce A5 e D3 |
| 10b | Ritenute d'acconto versate con F24 su compensi a terzi | **Stessa voce del compenso** (es. A2/B2 "Servizi"): fanno parte del compenso lordo, l'ente versa solo come sostituto d'imposta | Segue il compenso | [V] voci "Servizi"; [CTS] (F24) — ragionamento del notebook, coerente col principio di cassa |
| 10c | IRES (ed eventuale IRAP) | Riga **"Imposte"** fra "Avanzo/disavanzo prima delle imposte" e "Avanzo/disavanzo prima di investimenti..." | — | [V] struttura del Modello D |
| 10d | IVA versata sulle attività commerciali | **Aperto**: riga "Imposte" oppure **B5**. Vedi sotto | — | vedi nota |
| 11 | Acquisto di beni durevoli | Fino a **516,46 €** a costo in **A1**; oltre, in **"Investimenti in immobilizzazioni inerenti alle attività di interesse generale"** (riga 1 della sezione investimenti), o riga 2 se per attività diverse | No | [V] ("in analogia con la normativa fiscale si può utilizzare il limite dei 516 euro") |

**Note ai casi**

- **(1) Lordo/netto.** Il Vademecum indica in D1 "interessi passivi, etc." e non nomina espressamente PayPal o Satispay: assimilarne le commissioni agli oneri bancari è un'interpretazione, condivisa dalle fonti e coerente con la categoria esistente "Canoni e commissioni bancarie". Registrare al lordo è necessario perché le entrate per voce (es. A1) risultino corrette e perché le soglie e i test usano le entrate lorde.
- **(8) Festa della Torre.** La condizione per C2 è l'occasionalità "in concomitanza di celebrazioni, ricorrenze o campagne di sensibilizzazione" [CTS art. 79 c. 4 lett. a]. Una festa annuale di pochi giorni rientra nello schema tipico descritto dalle fonti, ma servono il rendiconto specifico e la relazione illustrativa (art. 87 c. 6). Se la somministrazione diventa continuativa o organizzata in forma d'impresa, la voce è B3 e la natura è commerciale. In nessun caso la voce è A7: la somministrazione al pubblico non è un'attività di interesse generale dell'associazione, e per le APS l'art. 85 c. 1 decommercializza solo le attività verso gli associati.
- **(10a) Bollo.** L'art. 82 c. 5 CTS esenta dall'imposta di bollo "gli atti, i documenti, ... gli estratti, le certificazioni ..." degli ETS. Conviene verificare con la banca se il bollo sul conto è ancora dovuto dopo l'iscrizione al RUNTS. Finché viene addebitato, la voce resta A5.
- **(10d) IVA.** NotebookLM afferma che la riga "Imposte" comprende anche l'IVA, ma i passaggi citati riportano solo lo schema del Modello D, senza dire cosa contenga quella riga: **non confermato**. Con il regime forfettario dell'art. 86 CTS (dal 2026) l'IVA non si applica alle operazioni [WAdE, domanda 7], quindi il caso riguarda soprattutto l'esercizio 2025 (L. 398/91). Proposta prudente: classificare l'IVA versata sulle attività commerciali in **B5** (uscita dell'attività diversa che la genera) e chiedere conferma al commercialista se vada invece nella riga "Imposte".

## Categorie della Mappatura classificate in modo dubbio

Le categorie sono quelle del foglio Mappatura 2025. Una categoria che nomina un esercizio specifico è riportata in forma generalizzata ("collaborazione con esercizio commerciale").

| Categoria | Voce attuale | Voce proposta | Comm. | Motivo e fonte |
|---|---|---|---|---|
| Festa della Torre, entrate somministrazione | A7 | **C2** (con rendiconto specifico); in alternativa B3 | C2 no / B3 sì | Caso 8. [V] voce C2; [CTS] artt. 79 c. 4 lett. a, 85 c. 3, 87 c. 6 |
| Festa della Torre, somministrazione (uscita) | A1 | **C2 uscite** (o B1 se la Festa va in B) | — | Le uscite seguono la sezione delle entrate correlate [V] |
| Festa della Torre, altre spese | A1 | **C2 uscite** (o B1) | — | come sopra |
| Festa della Torre, servizi | A2 | **C2 uscite** (o B2) | — | come sopra |
| Imposte festa della Torre | A5 | **C2 uscite** (o B5) | — | come sopra |
| Gadget a soci | A3 | **B1 "Entrate per prestazioni e cessione ad associati e fondatori"** (attività diverse); C2 se ceduti come beni di modico valore in una raccolta occasionale | **Sì** | Art. 85 c. 3 CTS: le cessioni di beni nuovi prodotti per la vendita sono "comunque commerciali" anche verso i soci. [CTS]; [T2]; [T3] |
| Provvigioni raccolta pubb | B4 Personale | **B2 Servizi** | — | "Personale" = solo lavoro contrattualizzato (subordinati, co.co.co.) [T2]; le prestazioni occasionali sono servizi |
| Ritenuta d'acc raccolta pubb | B4 Personale | **B2 Servizi** (stessa voce del compenso); meglio fonderla con la categoria del compenso | — | Caso 10b |
| IVA | A5 | **B5** (proposta prudente) o riga "Imposte"; da confermare | — | Caso 10d: l'IVA nasce dalle attività commerciali (sezione B), non dall'interesse generale |
| Rimborsi spese Si può fare | E5 | **A5** | No | "Si può fare" è un'iniziativa di interesse generale; i rimborsi ai volontari vanno nella sezione dell'attività [V] voce A5 |
| Strumenti e attrezzature | A1 | **A1 fino a 516,46 €; oltre, "Investimenti in immobilizzazioni inerenti alle attività di interesse generale"** | — | Caso 11 [V]. Serve una categoria distinta per i beni sopra soglia |
| Strumenti e materiali Si può fare / Strumenti e materiali MICROPROG Si può fare | A1 | come sopra | — | come sopra |
| Entrate MICROPROG Si può fare | A10 "Altre entrate" | **A8** se il denaro viene da un ente pubblico, **A6** se da un privato; A10 solo se nessuna voce specifica si applica | No | A10 è residuale [V]; le fonti del microprogetto vanno identificate |
| Erogazioni liberali | A4 | A4 per le liberalità spontanee; **C1/C2** per quelle raccolte con campagne o eventi; **A1** per le quote pagate come "donazione" | No | Casi 3 e 6. [LG] |
| Entrate aggregazione e convivialità | A2 | **A2 corretta per una APS** (corrispettivi dei soci per attività istituzionali); da verificare la natura | Da verificare | [T2]: A2 "solo APS" per le quote dei soci per partecipare ad attività di interesse generale. Attenzione: la "somministrazione di pasti" è commerciale anche verso i soci (art. 85 c. 3 CTS), salvo l'eccezione del c. 4 per le APS nazionali riconosciute dal Ministero dell'Interno |
| Imposta di bollo c/c | A5 | A5 (corretta) | — | [V] voce A5; da verificare l'esenzione art. 82 c. 5 CTS (nota 10a) |

### Categorie verificate come corrette

- **A1:** Tesseramento ord.
- **A4:** Erogazioni liberali, per le liberalità spontanee.
- **A5 entrate:** Entrata 5 per mille.
- **A6:** Palio e corteo, contrib. Magistrato.
- **A8:** Contributo Carnevale da comune; Contributo Si può fare da comune; Contributo Si può fare da ministero. Vale purché siano contributi a fondo perduto e non corrispettivi.
- **B3, commerciali:** Pubblicità e sponsorizzazioni; collaborazione con esercizio commerciale.
- **B1 uscite:** Materiale pubblicitario c/sponsor.
- **D1:** Canoni e commissioni bancarie (comprese le commissioni PayPal e Satispay, al lordo); Competenze e interessi c/c.
- **A5 uscite:** Quota adesione magistrato e Erogazioni liberali ad altri enti. Il [V] elenca in A5 "quote associative" ed "erogazioni ad altri enti".
- **Movimenti tra conti** (Prelievo, Versamento): esclusi dal rendiconto, correttamente.

## Conseguenze per la webapp (ticket #12)

1. Ogni **categoria** deve portare, oltre alla voce Modello D, un attributo di natura commerciale indipendente dalla sezione (caso C1 corrispettivo, A7 oltre il 6%).
2. Le commissioni dei fornitori di pagamento vanno registrate come **movimento separato** in D1 accanto all'incasso lordo, anche quando l'estratto conto PayPal o Satispay riporta solo il netto.
3. Le raccolte fondi occasionali (sezione C2) richiedono di raggruppare entrate e uscite per **iniziativa**, per produrre il rendiconto specifico dell'art. 87 c. 6.
4. Gli acquisti di beni durevoli richiedono una soglia (516,46 €) che separa A1 dagli investimenti.

## Punti aperti da confermare con il commercialista

- IVA versata (2025, L. 398/91): riga "Imposte" o B5.
- Festa della Torre: conferma dell'inquadramento come raccolta occasionale (C2) e predisposizione del rendiconto specifico.
- Cene sociali e momenti conviviali per soci: natura commerciale alla luce dell'art. 85 c. 3 CTS.
- Esenzione dal bollo sul conto corrente (art. 82 c. 5 CTS).
