# Conversione delle vecchie causali

Proposta di tabella per l'import iniziale dal foglio di calcolo (#19). Traduce ogni "Nuova causale" del foglio in **tipologia**, **area**, **attività**, **iniziativa** ed eventuali **tag**. L'app la usa per la cassa 2026 e per gli esempi di classificazione del 2025.

La tabella è in [`conversione-causali.csv`](conversione-causali.csv). Contiene solo le etichette delle causali, nessun dato reale. Parte dalla Mappatura del foglio 2026 e dalle correzioni proposte in #8. Il tesoriere la rivede una volta.

Questo file è solo la **tabella di partenza**. L'app la carica all'avvio e da lì la tabella si modifica in una pagina dell'app, finché il foglio è in uso (#19):
- una riga cambiata si riapplica alle righe importate *da classificare* o *proposte*;
- quelle *confermate* restano come sono, ma l'app le elenca per correggerle in blocco;
- alla data di passaggio la tabella si chiude insieme all'import.

## Come si legge

Una riga per causale, comprese quelle mai usate. Il confronto con la causale ignora il prefisso "->" o "→" e gli spazi finali.

| Colonna | Contenuto |
|---|---|
| `causale` | Il testo della "Nuova causale" nel foglio |
| `verso` | entrata, uscita, giroconto |
| `tipologia` | Vedi l'elenco sotto. Se è vuota, la riga arriva come dubbio. |
| `area` | La sezione del Modello D, da A a E |
| `attivita` | Nell'area A la lettera dell'art. 5 CTS; nell'area B l'attività diversa. Se è vuota, l'imputazione si ferma all'area. |
| `iniziativa` | Se è vuota, il movimento non appartiene a un'iniziativa |
| `tag` | Tag da aggiungere |
| `voce` | La voce Modello D che risulta da area + tipologia |
| `voce_mappatura_2026` | La voce che la causale aveva nel foglio, per il confronto |
| `controllo` | Che cosa l'import deve segnalare in revisione |
| `nota` | Il motivo di una differenza, con il riferimento alla decisione |

Una riga che cambia voce rispetto alla Mappatura ha la nota che spiega perché.

## Tipologie proposte

La voce Modello D è la riga indicata, nella sezione dell'area dell'imputazione. Per le uscite le aree A, B ed E hanno le stesse righe.

**Uscite**

| Tipologia | Riga | Note |
|---|---|---|
| Materiali e beni di consumo | 1 | |
| Strumenti e attrezzature | 1 | Oltre 516,46 euro diventa un investimento (#8, caso 11) |
| Acquisti per la somministrazione | 1 | Alimenti e bevande |
| Materiale di comunicazione | 1 | |
| Libri e pubblicazioni | 1 | |
| Servizi professionali | 2 | |
| Servizi internet e software | 2 | |
| Assicurazioni | 2 | |
| Utenze | 2 | |
| Formazione dei volontari | 2 | |
| Servizi vari | 2 | |
| Prestazioni occasionali | 2 | Non "Personale", che vale solo per il lavoro contrattualizzato (#8) |
| Provvigioni | 2 | Comprende la ritenuta d'acconto sul compenso (#8, caso 10b) |
| Affitti e noleggi | 3 | |
| Rimborsi spese ai volontari | 5 | Mai in "Personale" (#8) |
| Quote associative ad altri enti | 5 | |
| Erogazioni liberali ad altri enti | 5 | |
| Spese di rappresentanza | 5 | |
| Imposte e tasse | 5 | |
| IVA | Imposte | La riga "Imposte" del Modello D, dopo l'avanzo prima delle imposte (verificato sul notebook) |
| Commissioni e oneri bancari | D1 | Comprende le commissioni PayPal e Satispay (#8) |

**Entrate**

| Tipologia | Voce | Proprietà |
|---|---|---|
| Quote associative | A1 | |
| Corrispettivi dei soci per attività | A2 | |
| Cessioni ai soci | A3 | |
| Erogazioni liberali | A4 | Liberalità |
| 5 per mille | A5 | |
| Contributi da privati | A6, B2 | |
| Somministrazione | A7, B3 | Esclusa dalla decommercializzazione (art. 85 c. 3 CTS) |
| Corrispettivi da terzi | A7, B3 | |
| Contributi da enti pubblici | A8, B4 | |
| Altre entrate | A10, B6 | |
| Sponsorizzazioni e pubblicità | B3 | Commerciale; neutra nel test dell'ente (art. 79 c. 5 CTS) |
| Interessi attivi | D1 | |

Nessuna causale attuale va in area C. Le raccolte fondi useranno le stesse tipologie, con la voce C1 o C2.

## Attività

- **Area A**: lettere dell'art. 5 c. 1 CTS previste dallo statuto (art. 5): f, i, k, l, w, z. Nella tabella compaiono:
  - **f**, tutela e valorizzazione del patrimonio culturale: Palio e Corteo, perché lo statuto lo indica come riferimento primario;
  - **i**, attività culturali, artistiche o ricreative di interesse sociale: Festa della Torre, Carnevale, Infiorata, Attività natalizie, Aggregazione e convivialità.
- **Area B**: lo statuto non elenca le attività diverse (art. 5 c. 6), quindi i nomi sono proposti qui: "Sponsorizzazioni e pubblicità" e "Collaborazioni con esercizi commerciali".

## Iniziative e tag

- **Iniziative**: Palio e Corteo, Carnevale, Infiorata, Festa della Torre, Si può fare, Attività natalizie, Aggregazione e convivialità, La Casa.
- **La Casa** è un'iniziativa trattata come progetto, senza attività. Le sue spese vanno in area E (#19).
- **Tag**: "Microprogetto" per le causali MICROPROG di Si può fare; "Gruppo CLT" per le spese del gruppo al Carnevale.

## Differenze rispetto alla Mappatura 2026

- **Spese generali in area E** (#12): materiale di consumo, servizi internet, assicurazioni, rappresentanza, bollo e altre imposte passano dalla sezione A alla E.
- **La Casa in area E**: materiale, utenze, servizi, affitto e imposte.
- **Si può fare**:
  - le prestazioni d'opera passano da A4 ad A2;
  - i rimborsi spese passano da A4 ad A5.
- **Raccolta pubblicitaria**: provvigioni e ritenute passano da B4 a B2.
- **IVA**: passa da A5 alla riga "Imposte" (verificato sul notebook).
- **Rappresentanza**: una sola tipologia invece di due righe diverse (A1 e A5).
- **Gadget a soci**: la causale viene tolta. Se ricompare nel foglio, la riga arriva come dubbio.

## Decise in revisione (3/11/2026)

- **Si può fare**: l'iniziativa non si è ripetuta e le sue righe servono solo per gli esempi 2025. "Si può fare NO" non ha tipologia e non diventa un esempio.
- **Causali generiche**: tipologia "Materiali e beni di consumo", da verificare in revisione.
- **Attività diverse**: i nomi proposti vanno bene.
- **Bollo sul c/c**: esente dalla fine del 2025, quindi la causale resta solo per gli esempi 2025.

## Domande ancora aperte

1. **Spese generali in area E.** Lo spostamento tocca soltanto il test dell'art. 79 c. 2-bis, e solo in parte: secondo la Circolare 1/E § 2.2.1, una quota dei costi generali torna all'area A in proporzione ai ricavi.
2. **Vendita bandiere.** Il tesoriere la indica come attività di interesse generale svolta in forma commerciale (area A). Secondo il notebook, invece, la vendita di beni non rientra in nessuna lettera dell'art. 5: diventa un'attività diversa (area B) oppure una raccolta fondi.
3. **Lettera di Palio e Corteo.** Il notebook indica i, con f come alternativa. La tabella usa f perché lo statuto (art. 5 c. 2 lett. a) lega il Palio alla tutela del patrimonio storico-culturale. Sotto i 300.000 euro le attività di interesse generale si considerano come una sola, quindi la lettera non cambia i test. Per le altre iniziative il notebook conferma i.
