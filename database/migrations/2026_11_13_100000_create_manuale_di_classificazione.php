<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il manuale di classificazione (#44) con la sua prima stesura. Il testo è scritto qui e non letto da un file di
 * docs/ perché `docs/` non viene pubblicato su Aruba e lì le migrazioni partono da una pagina (come per #39).
 *
 * Fonti: ricerca #8, decisioni di #12, #15 e #17, docs/import-foglio/README.md. Le regole sono riformulate, gli
 * esempi sono inventati. L'elenco delle tipologie non è qui: la pagina lo ricava dalla tabella `tipologie`.
 * Il codice di una sezione è il suo numero («manuale § 9»): non cambia e non si riusa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuale_sezioni', function (Blueprint $table) {
            $table->id();
            $table->string('codice', 20)->unique();
            $table->string('titolo');
            $table->mediumText('corpo'); // Markdown; `text` su MySQL tiene 65.535 byte, non caratteri
            $table->timestamps();
        });

        $ora = ['created_at' => now(), 'updated_at' => now()];
        foreach ($this->sezioni() as $i => [$titolo, $corpo]) {
            DB::table('manuale_sezioni')->insert(['codice' => (string) ($i + 1), 'titolo' => $titolo, 'corpo' => trim($corpo)] + $ora);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('manuale_sezioni');
    }

    /** @return list<array{string, string}> */
    private function sezioni(): array
    {
        return [
            ['Come si usa questo manuale', <<<'T'
Il manuale spiega come si classificano i movimenti e perché. Lo legge chiunque entri nell'app; lo modificano il Tesoriere e l'Amministratore. Ogni modifica lascia una versione nel registro delle modifiche, con il testo di prima e di dopo.

Ogni sezione ha un numero che non cambia, nemmeno se si cambia il titolo: si cita come «manuale § 9». L'AI riceve il manuale intero e, quando propone una classificazione, indica la sezione su cui si basa.

Le regole qui dentro sono scelte dell'associazione, non pareri di un consulente. Dove una scelta aspetta la conferma del commercialista c'è scritto «da confermare con il commercialista», e le stesse scelte sono raccolte nel § 18.
T],

            ['I principi di base', <<<'T'
- **Cassa.** Un movimento si registra quando i soldi entrano o escono dal conto, non quando si decide la spesa o si emette il documento.
- **Prima l'area, poi la tipologia.** L'area dice che tipo di attività è (§ 3); la tipologia dice che natura ha il movimento (§ 4). La **voce del Modello D** non si sceglie: si ricava dalla coppia area e tipologia.
- **L'iniziativa è facoltativa.** Quando c'è, di solito porta con sé area e attività. Un movimento può fermarsi all'attività o all'area.
- **I tag servono solo a filtrare.** Non cambiano mai il Modello D né i test.
- **La natura commerciale dipende dalla tipologia e dai test, non dall'area.** Una voce in area A può essere commerciale, una in area B di regola lo è. Si cambia solo con una forzatura motivata.
- **Si registra il lordo.** L'entrata per il suo importo pieno, i costi di incasso come uscita a parte (§ 6).
- **La scelta fatta a mano prevale** su regole e riclassificazioni automatiche.
- **Nel dubbio** si sceglie la soluzione più prudente e si segna il dubbio (§ 17).
T],

            ['Aree e attività', <<<'T'
Le aree sono le sezioni del Modello D:

- **A, attività di interesse generale.** L'attività è la lettera dell'art. 5 del Codice del Terzo Settore prevista dallo statuto (f, i, k, l, w, z). Per le iniziative culturali, artistiche e ricreative si usa la lettera **i**; per Palio e Corteo l'alternativa è la **f**, perché lo statuto lega quell'attività alla tutela del patrimonio culturale. Con entrate sotto i 300.000 euro la lettera scelta non cambia l'esito dei test. **Da confermare con il commercialista** (§ 18).
- **B, attività diverse.** Lo statuto non le elenca: i nomi in uso sono «Sponsorizzazioni e pubblicità», «Collaborazioni con esercizi commerciali» e «Vendita di beni».
- **C, raccolta fondi.** Per ora nessun movimento ci va. Una raccolta occasionale in concomitanza di una ricorrenza richiede un rendiconto a parte: prima di usare quest'area si decide con il commercialista.
- **D, attività finanziarie e patrimoniali.** Interessi attivi, commissioni e canoni di banca.
- **E, supporto generale.** Le spese comuni a tutta l'associazione (§ 15).

Un'iniziativa ha, per ogni esercizio, un'area e un'attività predefinite: i suoi movimenti le prendono da lì, e sul singolo movimento si possono cambiare. Se si sposta un'iniziativa da un'area a un'altra, i suoi movimenti degli esercizi non depositati si riclassificano in blocco; quelli cambiati a mano non si toccano ma vengono segnalati.
T],

            ['Le tipologie', <<<'T'
Ogni imputazione ha una tipologia, di entrata o di uscita. La spiegazione in parole semplici di ciascuna è in fondo al manuale, nel paragrafo «tipologie» (§ tipologie): ogni tipologia ha il suo punto di arrivo, e dalla pagina Classificazione c'è un rimando diretto.

Le uscite delle aree A, B ed E hanno le stesse righe: «Materiali e beni di consumo» è la riga 1 in tutte e tre. Per le entrate la riga cambia con l'area (§ 7 e seguenti).

Alcune tipologie hanno proprietà fiscali: «commerciale per natura» e «neutra nel test dell'ente». Una volta che una tipologia è stata usata, le proprietà non si cambiano dalla pagina Classificazione, perché cambierebbero il passato: serve la riclassificazione in blocco.

Per le causali generiche, che non dicono che spesa sia, si usa «Materiali e beni di consumo» e si segna il dubbio.
T],

            ['Giroconti', <<<'T'
Spostare soldi tra due conti dell'associazione non è né un'entrata né un'uscita: è un **giroconto**. Non compare tra le entrate e le uscite del Modello D, ma solo nei saldi finali di cassa e banca.

Vale per un versamento dalla cassa in banca, per un prelievo, per il passaggio da Satispay o PayPal al conto bancario. Un giroconto ha due metà, una per conto, che vanno abbinate; una metà senza compagna resta segnalata finché non si trova l'altra.

*Esempio.* La cassa della festa consegna 300 euro in banca: sul conto di cassa c'è un'uscita di 300 euro, sul conto bancario un'entrata di 300 euro. Abbinate, spariscono dal rendiconto.
T],

            ['Commissioni di PayPal, Satispay e banca', <<<'T'
Quando un incasso passa da PayPal o da Satispay, nel rendiconto vanno due cose separate: l'incasso per il suo importo **pieno**, nella voce di entrata che gli compete, e la commissione come uscita nella tipologia «Commissioni e oneri bancari», voce **D1**. Lo si fa anche quando l'estratto riporta solo l'importo netto: così le entrate per voce e i test, che usano le entrate lorde, tornano.

I canoni e le commissioni della banca vanno nella stessa tipologia.

Per Satispay le commissioni si registrano una volta al mese dal riepilogo, e il versamento sul conto bancario è un giroconto (§ 5).

*Esempio.* Una quota di 25 euro arriva a PayPal e il conto ne accredita 24,10. Si registrano 25 euro in «Quote associative» e 0,90 euro in «Commissioni e oneri bancari».
T],

            ['Quote associative, donazioni e corrispettivi dei soci', <<<'T'
- **Quota associativa**: tipologia «Quote associative», voce **A1**. Conta la sostanza e non il canale: una quota pagata con il pulsante «Dona» di PayPal resta una quota.
- **Erogazione liberale**: una somma data spontaneamente, senza nulla in cambio. Tipologia «Erogazioni liberali», voce **A4**.
- **Corrispettivo di un socio**: quanto un socio paga per partecipare a un'attività dell'associazione. Tipologia «Corrispettivi dei soci per attività», voce **A2**, con l'iniziativa. I pasti dati ai soci a pagamento sono un caso a parte (§ 9) e le vendite di beni ai soci un altro ancora (§ 11).

*Esempio.* Un socio versa 20 euro per il rinnovo usando «Dona»: è una quota. Una persona versa 50 euro con la causale «un aiuto per il Carnevale»: è un'erogazione liberale.
T],

            ['Contributi e 5 per mille', <<<'T'
- **Contributo a fondo perduto di un ente pubblico** (Comune, Regione, ministero): «Contributi da enti pubblici», voce **A8**. Vale se l'ente non riceve nulla in cambio. Se il contributo è destinato a un'attività commerciale (area B) la voce è **B4**.
- **Somma pagata da un ente pubblico per un servizio reso** (convenzione, appalto): non è un contributo ma un corrispettivo, e il Modello D ha per questo caso una riga propria, A9, che oggi la tabella delle voci non prevede. Se capita, si usa «Corrispettivi da terzi» e si segna il dubbio.
- **Contributo di un'azienda, una fondazione o un privato** per un'iniziativa: «Contributi da privati», voce **A6**. Se il contributo è destinato a un'attività commerciale (area B) la voce è **B2**.
- **5 per mille**: «5 per mille», voce **A5**.
- **Altre entrate** (A10) è la riga di chi non sa dove mettere: si usa solo se nessuna delle altre si applica.

Per il Microprogetto di «Si può fare» non basta scrivere «Altre entrate»: si guarda da dove viene il denaro, A8 se da un ente pubblico, A6 se da un privato, e si aggiunge il tag «Microprogetto».

*Esempio.* Il Comune versa 800 euro per sostenere il Carnevale senza chiedere nulla in cambio: «Contributi da enti pubblici», iniziativa Carnevale.
T],

            ['Cene, somministrazione e Festa della Torre', <<<'T'
Per un'APS la somministrazione di pasti e bevande è **un'attività commerciale** (art. 85 c. 3 CTS), anche quando è rivolta ai soci. Fa eccezione l'art. 85 c. 4, per le APS affiliate a una rete nazionale con finalità assistenziali riconosciute dal Ministero dell'Interno: l'associazione oggi non rientra, e se un giorno rientrasse sarebbe un'impostazione dell'ente valida da un esercizio in poi.

- **Cene e momenti conviviali per i soci**: tipologia «Somministrazione ai soci», commerciale per natura. È un'ipotesi prudente: le fonti non chiariscono se il test dell'art. 79 possa renderla non commerciale. Da confermare con il commercialista.
- **Somministrazione al pubblico**: tipologia «Somministrazione».
- **Acquisti di cibo e bevande**: «Acquisti per la somministrazione».

**La Festa della Torre** è una scelta del tesoriere: tutte le entrate, compresa la somministrazione, vanno in **A7** (area A, tipologia «Somministrazione»), e le uscite restano nell'area A. La ragione: la festa di piazza con musica dal vivo è un'attività di interesse generale; la somministrazione serve a finanziare i costi della parte ricreativa e le entrate seguono l'attività principale. La somministrazione resta comunque commerciale. Il controllo che conta è il test dell'art. 79 c. 2-bis: i corrispettivi dell'attività non devono superare i costi effettivi; è tollerato uno scarto fino al 6%, ma al massimo per tre esercizi consecutivi, poi si applica la soglia piena. Per questo l'iniziativa deve raccogliere **tutti** i costi, compresi musica, palco, service, SIAE e permessi, così che il conto per iniziativa mostri quanto si avvicina al pareggio. **Da confermare con il commercialista**; se cambierà, lo si cambia in blocco spostando l'iniziativa di area.

*Esempio.* Il sabato sera i tavoli incassano 1.200 euro: «Somministrazione», iniziativa Festa della Torre, A7. Gli 800 euro di carne e bibite comprati per la sera: «Acquisti per la somministrazione», stessa iniziativa. Il noleggio del palco da 500 euro: «Affitti e noleggi», stessa iniziativa.
T],

            ['Sponsorizzazioni e pubblicità', <<<'T'
Le entrate da sponsor vanno in area **B**, attività «Sponsorizzazioni e pubblicità», tipologia «Sponsorizzazioni e pubblicità», voce **B3**. Sono commerciali per natura.

Due regole fisse, senza opzioni interpretative:

- Nel **test di secondarietà** (art. 6 CTS e DM 107/2021) le sponsorizzazioni **contano**: entrano tra le entrate delle attività diverse, e nel criterio del 30% pesano.
- Sono **neutre solo** nel test della natura non commerciale dell'ente (art. 79 c. 5 CTS).

**Attenzione alle fonti formative.** Alcune slide e un webinar degli incontri per i tesorieri le danno fuori dalla secondarietà. Non è la regola: l'esclusione vale solo per gli enti sportivi dilettantistici, mentre la Circolare dell'Agenzia delle Entrate 1/E del 2026 e il decreto le fanno rientrare. L'app segue la norma. Per questo le entrate dell'area B vanno tenute d'occhio rispetto alla soglia.

I costi legati alla raccolta: il materiale pubblicitario per lo sponsor è «Materiale di comunicazione» in area B (riga 1); provvigioni e ritenute sono nel § 12.

*Esempio.* Un'azienda locale versa 400 euro per un banner nel programma: «Sponsorizzazioni e pubblicità», area B. Se la stessa azienda dà 400 euro senza chiedere visibilità, è invece un contributo da privati (§ 8).
T],

            ['Vendita di beni e gadget', <<<'T'
Vendere beni nuovi prodotti per la vendita è un'attività commerciale anche quando l'acquirente è un socio (art. 85 c. 3 CTS).

- **Bandiere e simili a soci**: tipologia «Cessioni ai soci», area **B**, attività «Vendita di beni», voce **B1**. È un'ipotesi prudente: il tesoriere la considera un'attività di interesse generale svolta in forma commerciale, ma il notebook di riferimento esclude la vendita di beni dalle lettere dell'art. 5. Da confermare con il commercialista.
- **Vendite a chi non è socio**: «Corrispettivi da terzi», voce **B3**.
- La causale «gadget a soci» è stata tolta; se ricompare in un estratto, il movimento arriva come dubbio.

*Esempio.* Tre soci comprano una bandiera da 15 euro ciascuno: 45 euro in «Cessioni ai soci», area B. Un passante ne compra una per 20 euro: «Corrispettivi da terzi».
T],

            ['Rimborsi, compensi e ritenute', <<<'T'
- **Rimborsi spese documentati ai volontari**: «Rimborsi spese ai volontari», riga **5** dell'area dell'attività a cui si riferiscono. **Mai** in «Personale», che vale solo per il lavoro con contratto.
- **Compenso per un lavoro occasionale**: «Prestazioni occasionali», riga 2, ancora una volta non «Personale».
- **Compenso a un professionista con partita IVA** (commercialista, grafico, tecnico): «Servizi professionali», riga 2.
- **Provvigioni e ritenute d'acconto**: la ritenuta versata con l'F24 fa parte del compenso lordo, perché l'associazione la versa come sostituto d'imposta. Si classifica nella **stessa tipologia del compenso**: «Provvigioni» comprende la ritenuta.

*Esempio.* Un compenso di 500 euro lordi a chi raccoglie la pubblicità: 400 euro al collaboratore e 100 euro di ritenuta con l'F24. Sono due movimenti, entrambi «Provvigioni», entrambi in area B.
T],

            ['Beni durevoli', <<<'T'
Gli acquisti di strumenti e attrezzature fino a **516,46 euro** per bene si registrano come spesa, nella tipologia «Strumenti e attrezzature» (riga 1). Oltre quella cifra il singolo bene è un **investimento**: nel Modello D sta nelle righe degli investimenti e non tra le uscite di gestione.

Oggi l'app non ha una tipologia per gli investimenti: finché manca, un acquisto sopra soglia resta in «Strumenti e attrezzature» con il contrassegno di dubbio e la nota «investimento: oltre 516,46 euro» (§ 17). Conta il prezzo del singolo bene, non il totale dello scontrino.

*Esempio.* Un impianto audio da 1.100 euro è un investimento; dieci cavi da 12 euro ciascuno sono una spesa.
T],

            ['Imposte, IVA e bollo', <<<'T'
- **Imposte e tasse** (IMU, TARI della sede, imposta di bollo sugli atti, altre imposte): tipologia «Imposte e tasse», riga 5 dell'area. Se sono imposte generali, area E.
- **IRES e IRAP**: nella riga «Imposte» del Modello D, tra l'avanzo prima delle imposte e l'avanzo prima degli investimenti.
- **IVA versata**: tipologia «IVA», nella riga **«Imposte»** del Modello D e non in B5. È una decisione del 3/11/2026, verificata sul vademecum del Terzo Settore; **da confermare con il commercialista**. Dal 2026, con il regime forfettario dell'art. 86 CTS, l'IVA non si applica alle operazioni: il caso riguarda soprattutto l'esercizio 2025.
- **Bollo sul conto corrente**: dalla fine del 2025 è esente (art. 82 c. 5 CTS). La causale resta solo per gli esempi del 2025. **Da confermare con il commercialista** (§ 18).

*Esempio.* L'F24 del trimestre con 230 euro di IVA da versare: tipologia «IVA».
T],

            ['Spese generali e La Casa', <<<'T'
Le spese che servono a tutta l'associazione e non a una sola iniziativa vanno in **area E**: materiale di consumo, servizi internet, assicurazioni, spese di rappresentanza, imposte e bolli. Il motivo: nel test dell'art. 79 c. 2-bis contano per l'area A solo in quota, in proporzione ai ricavi (Circolare 1/E § 2.2.1). È un'ipotesi prudente. Riduce i costi dell'area A che entrano nel test; gli altri test non cambiano. **Da confermare con il commercialista.**

**La Casa** è un'iniziativa senza attività, trattata come spesa generale: le sue spese (materiale, utenze, servizi, affitto, imposte) vanno in area E.

Le spese di rappresentanza hanno una sola tipologia, qualunque sia l'occasione.

*Esempio.* L'assicurazione annuale da 300 euro: «Assicurazioni», area E, nessuna iniziativa. La bolletta della luce della Casa: «Utenze», area E, iniziativa La Casa.
T],

            ['Dalle vecchie causali del foglio alle tipologie', <<<'T'
Nel foglio di calcolo si sceglieva una sola «Nuova causale», che fondeva natura della spesa, iniziativa e area (per esempio «Entrate MICROPROG Si può fare»). Nell'app le scelte sono separate: **tipologia**, **iniziativa** (da cui di norma l'area) ed eventuali **tag**. Il principio resta lo stesso: si sceglie un'etichetta, la voce si ricava.

L'app carica all'avvio una tabella di conversione, che per ogni vecchia causale indica tipologia, area, attività, iniziativa e tag. Il tesoriere l'ha rivista una volta; finché il foglio è in uso la si modifica dall'app.

*Esempi.* «Servizi MICROPROG Si può fare» diventa la tipologia «Servizi vari», l'iniziativa «Si può fare» e il tag «Microprogetto». «Carnevale, gruppo clt» diventa «Materiali e beni di consumo», l'iniziativa «Carnevale» e il tag «Gruppo CLT».

**Cosa cambia rispetto alla Mappatura del foglio 2026:**

- le spese generali passano dall'area A alla E (§ 15), e le spese della Casa stanno in E;
- per «Si può fare» le prestazioni d'opera passano da A4 ad A2 e i rimborsi spese da A4 ad A5;
- provvigioni e ritenute della raccolta pubblicitaria passano da B4 a B2;
- l'IVA passa da A5 alla riga «Imposte»;
- la rappresentanza è una sola tipologia invece di due righe diverse;
- la vendita delle bandiere passa da A3 a B1 (§ 11).

«Si può fare» non si è ripetuta: le sue righe servono solo per gli esempi del 2025. La riga «Si può fare NO» non ha tipologia e non diventa un esempio.
T],

            ['Casi dubbi', <<<'T'
Quando non si sa come classificare un movimento si sceglie la soluzione **più prudente** e lo si contrassegna come «dubbio», con una nota che dice cosa serve (per esempio «chiedere al commercialista»). Il movimento resta così com'è, compare in un elenco dedicato e nell'elenco di controllo che precede l'approvazione dell'esercizio, ma non la blocca.

Prudente vuol dire, nell'ordine: commerciale piuttosto che non commerciale; spesa generale in area E piuttosto che dentro un'attività; la tipologia più semplice piuttosto che una forzatura.

Un dubbio non è un errore e non si nasconde: quando il consulente risponde, si aggiorna la regola in questo manuale e si riclassificano in blocco i movimenti interessati (solo negli esercizi non depositati).

*Esempio.* Un movimento di 700 euro per un tavolo pieghevole: non si sa se sia attrezzatura o investimento. Si lascia «Strumenti e attrezzature», si segna il dubbio e si scrive «verificare la soglia dei 516,46 euro».
T],

            ['Punti da confermare con i consulenti', <<<'T'
Scelte prese in via prudente, in attesa del parere del commercialista. Ognuna si può cambiare dall'app, anche in blocco. Quando arriva una risposta, si corregge qui e si toglie dall'elenco.

- **Festa della Torre in A7**, con la somministrazione come attività strumentale e il pareggio dell'iniziativa come prova (§ 9). L'alternativa da valutare è la raccolta fondi occasionale in area C, con rendiconto specifico, oppure l'area B.
- **IVA nella riga «Imposte»** (§ 14).
- **Cene sociali**: somministrazione ai soci commerciale (§ 9); se l'associazione entrasse in una rete nazionale riconosciuta (art. 85 c. 4) cambierebbe.
- **Spese generali in area E** (§ 15).
- **Vendita delle bandiere in area B** (§ 11).
- **Palio e Corteo: lettera i** e non f (§ 3).
- **Esenzione del bollo sul conto** dalla fine del 2025 (§ 14).
T],

            ['I test dell\'esercizio e le scelte di classificazione', <<<'T'
I test non si inseriscono: l'app li calcola dalle imputazioni, e per questo ogni scelta di classificazione ha un effetto. Questa sezione dice dove guardare; la spiegazione di ciascun test, con la sua base normativa, rimanda qui.

- **Test di secondarietà** (art. 6 CTS, DM 107/2021): ha due criteri, quello del 30% e quello del 66%; l'app li calcola entrambi e propone quello rispettato con più margine. Le sponsorizzazioni **contano** (§ 10); le imposte non entrano tra i costi complessivi del criterio del 66% e i proventi figurativi restano fuori dal denominatore del criterio del 30%.
- **Test dell'art. 79 c. 2-bis**, sulla natura non commerciale dell'area A: i corrispettivi dell'attività non devono superare i costi effettivi; è tollerato uno scarto fino al 6%, ma al massimo per tre esercizi consecutivi, poi si applica la soglia piena. I costi effettivi sono le uscite dell'area A più la quota delle spese generali, comprese imposte e oneri finanziari, senza costi figurativi. Se il test non è rispettato, i corrispettivi dell'area A diventano commerciali. Per questo le iniziative raccolgono **tutti** i loro costi (§ 9) e le spese generali stanno in area E (§ 15).
- **Test dell'art. 79 c. 5**, sulla natura non commerciale dell'ente: le sponsorizzazioni sono neutre (§ 10), mentre i contributi in B2 e B4 contano come entrate commerciali.

Una **forzatura** della natura commerciale cambia l'esito dei test: serve una motivazione, e finché la scelta è in dubbio si segue la soluzione prudente (§ 17).
T],
        ];
    }
};
