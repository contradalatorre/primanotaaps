# Test di secondarietà e soglie del Codice del Terzo Settore

Ricerca per il ticket [#6](https://github.com/contradalatorre/primanotaaps/issues/6) della mappa [#1](https://github.com/contradalatorre/primanotaaps/issues/1).

**Domanda.** Quali sono le regole esatte per (a) il carattere secondario e strumentale delle attività diverse (art. 6 CTS e DM 107/2021) e (b) le soglie dell'art. 13 (rendiconto per cassa), dell'art. 30 (organo di controllo) e dell'art. 31 (revisione legale)? Per ogni test serve una formula espressa in voci Modello D e l'elenco dei dati che non derivano dai movimenti. Vanno verificati anche i due rapporti che il foglio 2025 calcola oggi.

**Fonti.** Prima il notebook NotebookLM "Guida alla Contabilità e Rendicontazione degli Enti Terzo Settore" (citato come **[NB]** più il nome della fonte interna), poi i testi normativi sulle fonti ufficiali. Tutto consultato il **30/10/2026**. L'elenco completo è in fondo; nel testo le fonti sono citate come [N1], [N2] e così via.

Tutti gli importi negli esempi sono **inventati**.

## In sintesi

- **Le soglie del ticket sono superate.** La L. 104/2024, in vigore dal 3/8/2024, ha portato il limite del rendiconto per cassa da 220.000 a **300.000 €** (solo per enti senza personalità giuridica) e ha introdotto la forma aggregata (**Modello E**) fino a **60.000 €**. Ha alzato anche le soglie dell'organo di controllo (150.000 € di attivo, 300.000 € di entrate, 7 dipendenti) e della revisione legale (1,5 mln, 3 mln, 20 dipendenti) [N1][N2][N3][N6][NB].
- **Secondarietà: basta uno dei due criteri, esercizio per esercizio.** Le entrate da attività diverse (sezione B del Modello D) non devono superare il **30% delle entrate complessive** *oppure* il **66% dei costi complessivi**. Nei costi complessivi rientrano anche i costi figurativi (volontari iscritti al registro, erogazioni gratuite, differenza tra valore normale e costo). L'organo di amministrazione dichiara il criterio usato in un'annotazione in calce al rendiconto [N4][N1][NB].
- **I due rapporti del foglio 2025 sono i due criteri giusti, ma il secondo è incompleto se non somma i costi figurativi.** Il denominatore del 66% non è il totale delle uscite: è il totale delle uscite della gestione più i costi figurativi del DM 107/2021. Anche il perimetro del primo va controllato: entrate della gestione, senza disinvestimenti, finanziamenti e giroconti.
- **Le soglie si misurano sul bilancio dell'esercizio precedente.** Per il rendiconto per cassa si escludono le entrate da disinvestimenti e da finanziamenti: in pratica è il **Totale entrate della gestione** dell'anno prima [N5][NB]. Organo di controllo e revisione scattano solo se **due limiti su tre** sono superati per **due esercizi consecutivi**, e cessano dopo due esercizi consecutivi sotto soglia [N2][N3].
- **Diversi dati non vengono dai movimenti:** ore e qualifiche dei volontari iscritti al registro con la relativa retribuzione oraria, valore normale delle erogazioni gratuite in natura, totale dell'attivo, dipendenti medi, personalità giuridica, criterio scelto, data di approvazione del bilancio, esito del test nell'esercizio precedente. L'app deve chiederli a parte (elenco completo nella sezione 5).

## 1. Notazione: le voci Modello D

Il Modello D (DM 5 marzo 2020, Allegato 1) è a sezioni contrapposte [N5][NB: Vademecum rendiconto per cassa]. Nelle formule uso queste abbreviazioni:

| Simbolo | Voce Modello D |
|---|---|
| `EA` | Totale **A) Entrate da attività di interesse generale** (A1 quote associative … A10 altre entrate) |
| `EB` | Totale **B) Entrate da attività diverse**: B1 prestazioni e cessioni ad associati e fondatori, B2 contributi da soggetti privati, B3 prestazioni e cessioni a terzi, B4 contributi da enti pubblici, B5 contratti con enti pubblici, B6 altre entrate |
| `EC` | Totale **C) Entrate da attività di raccolta fondi** (C1 abituali, C2 occasionali, C3 altre) |
| `ED` | Totale **D) Entrate da attività finanziarie e patrimoniali** (D1–D5) |
| `EE` | Totale **E) Entrate di supporto generale**: E1 entrate da distacco del personale, E2 altre entrate di supporto generale |
| `TEG` | **Totale entrate della gestione** = EA + EB + EC + ED + EE |
| `UA`…`UE` | Totali delle sezioni **A)–E) Uscite**, ciascuna per natura (1 materie prime, 2 servizi, 3 godimento beni di terzi, 4 personale, 5 uscite diverse/altre uscite; la sezione D ha voci proprie) |
| `TUG` | **Totale uscite della gestione** = UA + UB + UC + UD + UE |
| `IMP` | **Imposte** |
| `EINV`, `UINV` | Entrate da disinvestimenti e ricevimento di finanziamenti (1–4) / uscite per investimenti e rimborso di finanziamenti (1–4) |
| `CF1`, `CF2` | **Costi figurativi** 1) da attività di interesse generale, 2) da attività diverse (prospetto facoltativo in calce) |

Il prospetto dei costi e proventi figurativi è facoltativo e non entra nei saldi di cassa [NB: Il bilancio degli ETS (2023)].

## 2. Test di secondarietà e strumentalità (art. 6 CTS, DM 107/2021)

### 2.1 Le regole

- **Base di legge.** Le attività diverse sono ammesse se lo statuto le consente e se sono secondarie e strumentali, secondo criteri del decreto ministeriale. Il decreto tiene conto anche delle risorse volontarie e gratuite [N1, art. 6].
- **Strumentalità (qualitativa).** Le attività diverse sono strumentali se, qualunque sia il loro oggetto, servono in via esclusiva alle finalità civiche, solidaristiche e di utilità sociale dell'ente [N4, art. 2]. Non ha una formula: va dichiarata nell'annotazione, con una descrizione di come i margini sostengono le attività di interesse generale [NB: slides incontro tesorieri 1; Il bilancio degli ETS].
- **Secondarietà (quantitativa).** Si considerano secondarie se, **in ciascun esercizio**, ricorre **una** delle due condizioni [N4, art. 3 c. 1]:
  - a) i relativi ricavi non superano il **30% delle entrate complessive** dell'ente;
  - b) i relativi ricavi non superano il **66% dei costi complessivi** dell'ente.
- **Costi complessivi, criterio b.** Comprendono *anche* [N4, art. 3 c. 3]:
  - a) i costi figurativi dei **volontari iscritti nel registro** (art. 17 c. 1 CTS): ore effettivamente prestate × retribuzione oraria lorda della qualifica corrispondente nei contratti collettivi (art. 51 D.Lgs. 81/2015);
  - b) le **erogazioni gratuite** di denaro e le cessioni o erogazioni gratuite di beni o servizi, al loro **valore normale**;
  - c) la **differenza tra valore normale e costo effettivo** dei beni o servizi acquistati per l'attività statutaria.
- **Esclusione.** I proventi e gli oneri del **distacco di personale** presso enti terzi non entrano né al numeratore né al denominatore di entrambi i rapporti [N4, art. 3 c. 4].
- **Documentazione.** L'organo di amministrazione documenta secondarietà e strumentalità «nella relazione di missione o in una annotazione in calce al rendiconto per cassa o nella nota integrativa» [N1, art. 13 c. 6]. Deve anche **indicare quale criterio ha usato** [N4, art. 3 c. 2]. Il glossario del DM 5 marzo 2020 prevede per il Modello D proprio questa «Annotazione prevista dall'art. 13, comma 6» [N5]. Il criterio può cambiare da un esercizio all'altro, perché la norma non chiede continuità [NB: Il bilancio degli ETS]. Il verbale ministeriale di controllo RUNTS verifica proprio la presenza dell'annotazione quando nel rendiconto ci sono entrate da attività diverse [NB: verbale_controllo_ordinario_runts].
- **Periodo.** L'esercizio, cioè l'anno solare per l'associazione [N4, art. 3 c. 1].

### 2.2 Formule in voci Modello D

```
Criterio a)   R30 = EB' / TEG'        rispettato se R30 ≤ 30%
Criterio b)   R66 = EB' / (TUG' + CFtot)   rispettato se R66 ≤ 66%
Esito         secondario = (R30 ≤ 30%) OPPURE (R66 ≤ 66%)
```

dove:

- `EB'` = EB (sezione B delle entrate), al netto di eventuali entrate da distacco di personale imputate in B;
- `TEG'` = TEG − E1 (entrate da distacco del personale);
- `TUG'` = TUG − uscite per il personale distaccato (sono dentro le voci "4) Personale", quindi l'app deve poterle marcare);
- `CFtot` = valore dei volontari iscritti + erogazioni gratuite in natura al valore normale + differenza valore normale/costo d'acquisto. In pratica coincide con `CF1 + CF2` se il prospetto in calce è compilato con queste sole componenti.

**Avvertenza sulle erogazioni gratuite in denaro.** In un rendiconto per cassa le erogazioni in denaro fatte dall'ente sono già uscite, dentro `TUG`. Il DM le cita perché nel bilancio di competenza possono non essere rilevate tra i costi. Nell'app vanno sommate a `CFtot` solo se non sono già movimenti: è un'interpretazione mia, nessuna fonte del notebook la tratta esplicitamente.

**Esempio inventato.** EB = 9.000 €, TEG = 25.000 €, TUG = 20.000 €, volontari iscritti 600 ore × 9 €/h = 5.400 €.
R30 = 9.000 / 25.000 = **36%** (criterio a non rispettato). R66 = 9.000 / (20.000 + 5.400) = **35,4%** (criterio b rispettato). L'ente è in regola e dichiara di aver usato il criterio b. La stessa logica è nell'esempio del notebook, dove un ente supera il 30% ma rientra nel 66% grazie ai costi figurativi dei volontari [NB: Il bilancio degli ETS].

### 2.3 Cosa succede se si superano entrambi i limiti

[N4, art. 4], confermato da [NB: verbale_controllo_ordinario_runts; Il bilancio degli ETS]:

1. **Segnalazione** all'ufficio RUNTS competente (ed eventualmente alla rete associativa autorizzata ex art. 93 c. 5) **entro 30 giorni dall'approvazione del bilancio**.
2. **Rientro nell'esercizio successivo.** Con lo **stesso criterio**, il rapporto deve stare sotto la soglia massima di almeno tanti punti quanti ne aveva superati. Esempio inventato: R30 = 34% (4 punti oltre il 30%), quindi l'anno dopo R30 ≤ 26%.
3. **Cancellazione dal RUNTS** (art. 50 CTS) se manca la segnalazione o il rientro. La cancellazione comporta la perdita della qualifica e la devoluzione del patrimonio incrementale [NB: Terzjus, "Il Terzo settore alla prova dei controlli"].

Per l'app servono quindi l'esito e la percentuale di superamento dell'esercizio precedente, e la data di approvazione del bilancio per calcolare la scadenza dei 30 giorni.

### 2.4 Casi di imputazione da chiarire

- **Raccolte fondi** (sezione C, art. 7 CTS): non sono attività diverse, quindi non vanno al numeratore. Entrano però al denominatore del criterio a (dentro TEG) e i loro costi al denominatore del criterio b [NB: Il bilancio degli ETS].
- **5 per mille**: è un'entrata della sezione A (A5), quindi entra solo nel denominatore del criterio a [NB: Il bilancio degli ETS].
- **Proventi finanziari e patrimoniali** (sezione D): secondo il notebook vanno esclusi dai ricavi da attività diverse, ma la fonte stessa dice che si attendono chiarimenti di prassi [NB: Il bilancio degli ETS].
- **Sponsorizzazioni e pubblicità** di un'APS non sportiva: sono attività diverse di natura commerciale e vanno in sezione B (B3 o B6) [NB: slides incontro tesorieri 2; slides_relatore_odcec]. Se entrino nel test è controverso: vedi i punti aperti (sezione 6).
- **Perimetro dei denominatori.** Il notebook conferma che il denominatore del criterio a è il solo Totale entrate della gestione e quello del criterio b il Totale uscite della gestione più i costi figurativi. Le sezioni investimenti/disinvestimenti e finanziamenti restano fuori. La fonte è la dottrina contabile; DM 107/2021 e Circolare AdE 1/2026 non fanno il raccordo con le righe del Modello D [NB: Il bilancio degli ETS; Vademecum].
- **Proventi figurativi al denominatore del criterio a?** Le slides del corso tesorieri dicono che al 30% «possono essere aggiunti i proventi figurativi» delle attività di interesse generale gratuite [NB: slides incontro tesorieri 1]. Il DM però prevede l'ampliamento del denominatore solo per il criterio b (art. 3 c. 3) [N4]. Per prudenza l'app non dovrebbe aggiungerli.

## 3. Verifica dei due rapporti del foglio 2025

Il foglio 2025 non è nel repo (contiene dati reali) e non l'ho aperto. La verifica è quindi sulla definizione dei due rapporti, così come è descritta nel ticket.

| Rapporto del foglio | Corrisponde a | Soglia | Cosa controllare nel foglio |
|---|---|---|---|
| Entrate da attività diverse / entrate totali | Criterio a (DM 107/2021, art. 3 c. 1 lett. a) | **≤ 30%** | Il denominatore deve essere il **Totale entrate della gestione**: senza giroconti, disinvestimenti, finanziamenti ricevuti e senza il saldo di cassa iniziale. Il numeratore è solo la sezione B. |
| Entrate da attività diverse / costi totali | Criterio b (DM 107/2021, art. 3 c. 1 lett. b) | **≤ 66%** | Il denominatore deve essere **Totale uscite della gestione + costi figurativi** (art. 3 c. 3). Le uscite per investimenti e i rimborsi di finanziamenti sono fuori, come conferma il notebook [NB: Il bilancio degli ETS; Vademecum]. Senza costi figurativi il rapporto è prudenziale: sovrastima l'indice e non è sbagliato, ma può far risultare un superamento che non c'è. |

Altri due punti da verificare nel foglio:

- **Esito.** L'ente è in regola se **almeno uno** dei due rapporti rispetta la propria soglia, non entrambi. Se il foglio applica la stessa soglia ai due rapporti, o richiede che li rispettino entrambi, è un errore.
- **Imposte.** Il Modello D le espone fuori dal totale uscite della gestione. Nessuna fonte consultata dice se vadano tra i costi complessivi: è un punto aperto.

## 4. Soglie dimensionali (artt. 13, 30, 31 CTS)

### 4.1 Rendiconto per cassa e forma aggregata (art. 13)

- **Rendiconto per cassa (c. 2).** Ammesso per gli ETS **privi di personalità giuridica** con «ricavi, rendite, proventi o entrate comunque denominate **non superiori a 300.000 euro**» [N1, art. 13, testo in vigore dal 3/8/2024]. Prima della L. 104/2024 la soglia era «inferiori a 220.000,00 euro» [NB: 20170802_179_SO_043, GU originaria].
- **Forma aggregata (c. 2-bis).** Ammessa per tutti gli ETS, con o senza personalità giuridica, con entrate **non superiori a 60.000 euro**. Lo schema è il **Modello E** (DM 18 febbraio 2026, GU n. 67 del 21/3/2026): riporta i soli totali di sezione del Modello D, senza cambiare il sistema di rilevazione [N6]. È una facoltà, ma una volta scelta il modello è vincolante. Per gli enti con esercizio sull'anno solare vale **dal rendiconto 2026** [N6]. Imprese sociali ed ETS commerciali sono esclusi [N6].
- **Esercizio di riferimento e calcolo.** Si considera il volume delle entrate «risultanti dal bilancio dell'**esercizio precedente**». Per i bilanci per cassa si escludono le entrate da **disinvestimenti** (alienazioni di immobilizzazioni) e quelle da **reperimento di fonti finanziarie** [N5, Allegato 1, introduzione]. Lo stesso dicono [NB: Vademecum rendiconto per cassa; Il bilancio degli ETS; slides incontro tesorieri 1].
- **Modello E e test di secondarietà.** Anche con il rendiconto aggregato l'annotazione dell'art. 13 c. 6 resta dovuta, e resta l'obbligo di una contabilità sottostante [NB: slides incontro tesorieri 2]. Le formule della sezione 2.2 usano solo totali di sezione (EB, TEG, TUG) più i dati extracontabili, quindi funzionano anche con il Modello E.
- **Superamento.** Se l'esercizio precedente supera la soglia, nell'esercizio corrente serve il bilancio per competenza (Modelli A, B, C) [NB: slides incontro tesorieri 1; Il bilancio degli ETS]. Sotto la soglia, il bilancio per competenza resta comunque facoltativo [NB: Vademecum].

```
Soglia13(t) = TEG(t−1)                 (esclude già EINV e i giroconti)
Cassa ammessa in t    se Soglia13(t) ≤ 300.000 € e l'ente non ha personalità giuridica
Modello E ammesso in t se Soglia13(t) ≤  60.000 € (dall'esercizio 2026)
```

### 4.2 Organo di controllo (art. 30) e revisione legale (art. 31)

| | Organo di controllo, associazioni (art. 30 c. 2) | Revisione legale (art. 31 c. 1) |
|---|---|---|
| Totale dell'attivo dello stato patrimoniale | 150.000 € | 1.500.000 € |
| Ricavi, rendite, proventi, entrate comunque denominate | 300.000 € | 3.000.000 € |
| Dipendenti occupati in media nell'esercizio | 7 | 20 |
| Quando scatta | superati **2 limiti su 3** per **2 esercizi consecutivi** | idem |
| Quando cessa | limiti non superati per **2 esercizi consecutivi** (c. 3) | idem (c. 2) |
| Valori prima del 3/8/2024 | 110.000 € / 220.000 € / 5 | 1.100.000 € / 2.200.000 € / 12 |

Fonti: [N2][N3] per i valori in vigore; [NB: 20170802_179_SO_043] per quelli originari; [NB: slides incontro tesorieri 1] per il confronto. In entrambi i casi la nomina è obbligatoria anche quando ci sono patrimoni destinati (art. 10) [N2, c. 4][N3, c. 3]. Le funzioni di revisione possono essere affidate all'organo di controllo se è composto da revisori iscritti [N2, c. 6].

**Regime transitorio** (Circolare MLPS n. 6 del 9/8/2024, secondo [NB: slides incontro tesorieri 1]). L'innalzamento delle soglie non fa decadere l'organo di controllo già nominato, che resta fino a scadenza. Il revisore legale invece può essere revocato per sopravvenuta carenza dei requisiti di legge. Non ho letto direttamente la circolare 6/2024.

```
Supera30(t) = [ (Attivo(t) > 150.000) + (Entrate(t) > 300.000) + (DipMedi(t) > 7) ] ≥ 2
Obbligo organo di controllo nasce  se Supera30(t−1) e Supera30(t)
Obbligo cessa                       se non Supera30(t−1) e non Supera30(t)
(stessa forma per l'art. 31 con 1.500.000 / 3.000.000 / 20)
```

**Il parametro Entrate per gli artt. 30 e 31.** Il testo è lo stesso dell'art. 13 («ricavi, rendite, proventi, entrate comunque denominate»). Usare `TEG` dell'esercizio, cioè con la stessa esclusione di disinvestimenti e finanziamenti, è un'estensione mia: il DM 5 marzo 2020 la prevede espressamente solo per la soglia dell'art. 13.

**Il parametro Attivo per un ente in contabilità di cassa.** Il rendiconto per cassa non ha stato patrimoniale, quindi l'attivo non deriva dai movimenti. Un'approssimazione è disponibilità liquide a fine esercizio più beni durevoli e crediti, ma nessuna fonte consultata indica come stimarlo: è un dato da inserire a mano (vedi la sezione 6).

**Rilevanza pratica.** Un'associazione che resta sotto i 300.000 € di entrate supera al massimo un limite su tre dell'art. 30 solo se ha più di 150.000 € di attivo o più di 7 dipendenti medi. L'app può calcolare il test ogni anno e avvisare solo al secondo esercizio consecutivo.

## 5. Dati che non derivano dai movimenti

| Dato | Serve per | Fonte della regola |
|---|---|---|
| Criterio di secondarietà scelto (a o b) per l'esercizio | Annotazione art. 13 c. 6 | [N4] art. 3 c. 2 |
| Descrizione della strumentalità (come i margini sostengono le attività di interesse generale) | Annotazione | [N4] art. 2; [N1] art. 13 c. 6 |
| Registro dei volontari (art. 17 CTS): ore effettivamente prestate per volontario e per attività (interesse generale o diverse) | Costi figurativi, criterio b | [N4] art. 3 c. 3 lett. a |
| Qualifica di riferimento e retribuzione oraria lorda da CCNL (art. 51 D.Lgs. 81/2015) per ogni tipo di attività volontaria | Costi figurativi | [N4] art. 3 c. 3 lett. a |
| Erogazioni o cessioni gratuite di beni e servizi fatte dall'ente, con valore normale | Costi figurativi | [N4] art. 3 c. 3 lett. b |
| Beni o servizi acquistati sotto il valore normale: valore normale e costo effettivo | Costi figurativi | [N4] art. 3 c. 3 lett. c |
| Proventi e oneri da distacco di personale (marcatura dei movimenti, se capita) | Esclusione da entrambi i rapporti | [N4] art. 3 c. 4 |
| Esito del test e punti di superamento nell'esercizio precedente | Obbligo di rientro | [N4] art. 4 c. 2 |
| Data di approvazione del bilancio da parte dell'assemblea | Scadenza dei 30 giorni per la segnalazione | [N4] art. 4 c. 1 |
| Personalità giuridica sì/no | Ammissibilità del rendiconto per cassa | [N1] art. 13 c. 2 |
| Entrate dell'esercizio precedente (deriva dai movimenti solo se l'anno prima è nell'app; per il 2026 va inserito il dato del 2025) | Soglie artt. 13, 30, 31 | [N5]; [N2][N3] |
| Totale dell'attivo (stima di stato patrimoniale) per ogni esercizio | Artt. 30 e 31 | [N2][N3] |
| Numero medio di dipendenti nell'esercizio | Artt. 30 e 31 | [N2][N3] |
| Presenza di patrimoni destinati (art. 10) | Obbligo di organo di controllo e revisore a prescindere dalle soglie | [N2] c. 4; [N3] c. 3 |
| Organo di controllo o revisore già nominati e scadenza del mandato | Regime transitorio della circolare 6/2024 | [NB: slides incontro tesorieri 1] |

## 6. Punti aperti

- **Sponsorizzazioni di un'APS non sportiva nel test di secondarietà.** Le fonti divergono:
  - le slides del corso tesorieri e la trascrizione dell'incontro del 20/4/2026 dicono che sponsorizzazioni, pubblicità e raccolte fondi continuative (merchandising) sono attività diverse ma «neutre», cioè non entrano nella verifica [NB: slides incontro tesorieri 1; 20260420_trascrizione];
  - la Circolare AdE 1/2026 esclude le sponsorizzazioni dal test **di commercialità** dell'ente (art. 79 c. 5) per tutti gli ETS. Dai criteri del DM 107/2021 le esclude **solo per gli enti sportivi dilettantistici** iscritti al RASD, in base all'art. 9 c. 1-bis D.Lgs. 36/2021 (richiamato anche dall'art. 6 CTS) [NB: Circolare AdE 1/2026][N1, art. 6].

  La sola base normativa trovata vale per gli enti sportivi, quindi la lettura prudente per un'APS non sportiva è **includerle** nel numeratore. Va confermato nel ticket sulla classificazione. L'app può comunque marcarle con una categoria propria, così da calcolare il test in entrambi i modi.
- **Totale dell'attivo per un ente per cassa.** Nessuna fonte consultata indica come stimarlo senza stato patrimoniale.
- **Imposte nel denominatore del criterio b.** DM 107/2021 e Circolare AdE 1/2026 non ne parlano. La dottrina contabile usa il totale delle uscite della gestione, che nel Modello D esclude la riga Imposte [NB: Il bilancio degli ETS]. Per il diverso test dell'art. 79 c. 2 le imposte sono invece espressamente tra i costi effettivi (art. 26 D.L. 73/2022) [NB: Test di (non) commercialità ETS].
- **Circolare MLPS 6/2024.** Citata solo tramite fonti secondarie del notebook; non letta direttamente.
- **Test di commercialità (art. 79 CTS).** È un test diverso, fiscale, e non rientra in questo ticket. Le attività diverse non sono sottoposte al test di commercialità [NB: slides_relatore_odcec_22_01_2026_cts].

## Fonti

**Testi normativi e prassi ministeriale** (consultati il 30/10/2026):

- [N1] D.Lgs. 3 luglio 2017, n. 117, artt. 6 e 13, testo vigente (in vigore dal 3/8/2024), Normattiva: <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2017-07-03;117~art6>, <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2017-07-03;117~art13>
- [N2] D.Lgs. 117/2017, art. 30, testo vigente (in vigore dal 3/8/2024), Normattiva: <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2017-07-03;117~art30>
- [N3] D.Lgs. 117/2017, art. 31, testo vigente (in vigore dal 3/8/2024), Normattiva: <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:decreto.legislativo:2017-07-03;117~art31>
- [N4] DM Lavoro 19 maggio 2021, n. 107, GU n. 177 del 26/7/2021, artt. 1–4 (scheda GU: <https://www.gazzettaufficiale.it/eli/id/2021/07/26/21G00115/sg>; testo letto dalla stampa "atto completo" GU ripubblicata da CSVnet Toscana: <https://www.cesvot.it/storage/15262/dm_19.05.21_n.107.pdf>)
- [N5] DM Lavoro 5 marzo 2020, "Adozione della modulistica di bilancio degli enti del Terzo settore", GU n. 102 del 18/4/2020, Allegato 1 (introduzione, Mod. D, glossario). Testo letto dalla stampa "atto completo" GU: <https://www.commercialisti.brescia.it/images/____ATTO_COMPLETO____21042020.pdf>
- [N6] Ministero del Lavoro, Circolare n. 6 del 17/4/2026, "Art. 13, comma 2-bis CTS – D.M. 18 febbraio 2026. Utilizzo del rendiconto per cassa in forma aggregata": <https://lavoro.gov.it/temi-e-priorita-terzo-settore-e-responsabilita-sociale-delle-imprese/focus/circolare-n-6>
- L. 4 luglio 2024, n. 104, GU n. 168 del 19/7/2024, in vigore dal 3/8/2024: <https://www.normattiva.it/uri-res/N2Ls?urn:nir:stato:legge:2024-07-04;104> (la circolare [N6] la indica come la novella dell'art. 13; l'articolo della legge che modifica gli artt. 13, 30 e 31 non l'ho letto direttamente).

**Notebook [NB]** "Guida alla Contabilità e Rendicontazione degli Enti Terzo Settore" (NotebookLM), fonti interne citate:

- *DI-107-del-19052021-art6-CTS-attivita-diverse-ETS.pdf*: testo del DM 107/2021
- *20170802_179_SO_043.pdf*: GU originaria del CTS (soglie pre-2024)
- *2023_04_Il-bilancio-degli-enti-del-Terzo-settore.pdf*: manuale con formule ed esempi del test, documentazione in calce, superamento
- *Vademecum rendiconto per cassa – Cantiere Terzo Settore* (CSVnet, versione agosto 2026): schema del Modello D, soglia 300.000 €, esclusione dei disinvestimenti
- *slides incontro 20 aprile tesorieri 1.pdf* e *slides incontro 27 aprile tesorieri 2.pdf*: L. 104/2024, esercizio precedente, circolare 6/2024, sponsorizzazioni, Modello E
- *20260420_trascrizione.txt*: trascrizione dell'incontro tesorieri del 20/4/2026 (sponsorizzazioni)
- *Test di (non) commercialità ETS: regole e criteri operativi nel 2026*: costi effettivi nell'art. 79, solo per confronto
- *verbale_controllo_ordinario_runts.pdf*: punti di controllo RUNTS su annotazione e superamento
- *_Circolare_Terzo_Settore n. 1 del 19 febbraio 2026.pdf* e *Circolare 19/02/2026 n. 1 – Agenzia delle Entrate*: riepilogo dei criteri ed esclusione delle sponsorizzazioni per gli enti sportivi
- *Il Terzo settore alla prova dei controlli – Fondazione Terzjus*: effetti della cancellazione, nozione di entrate complessive
- *slides_relatore_odcec_22_01_2026_cts.pdf*: criteri alternativi, rapporto con il test di commercialità
