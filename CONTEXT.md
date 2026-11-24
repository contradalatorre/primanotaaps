# Prima nota APS

Contabilità per cassa di Contrada La Torre APS: registrazione dei movimenti dei conti dell'associazione, loro classificazione e produzione del rendiconto per cassa (Modello D) e dei test previsti dal Codice del Terzo Settore.

## Language

### Conti e movimenti

**Conto**:
Un luogo in cui l'associazione tiene denaro: una cassa contanti, il conto corrente bancario, il conto PayPal, il conto Satispay. Può esistere solo per un periodo, come una cassa aperta per una festa.
_Avoid_: fonte, registro, foglio

**Movimento**:
Una singola entrata o uscita di denaro su un conto, con data e importo; l'importo è quello che entra o esce davvero dal conto, al netto di eventuali commissioni trattenute.
_Avoid_: operazione, transazione, riga

**Estratto conto**:
L'elenco dei movimenti di un conto per un periodo, così come lo esporta il fornitore del conto.
_Avoid_: export, lista movimenti

**Commissione**:
La somma che il fornitore del conto (PayPal, Satispay) trattiene su un incasso: il movimento è al netto, e classificandolo la commissione diventa un'imputazione in uscita in "Commissioni e oneri bancari" (area D, voce D1) accanto a quella dell'incasso per il suo importo lordo.
_Avoid_: tariffa, fee, spesa

**Causale**:
L'etichetta breve con cui il fornitore del conto indica il tipo di operazione (es. "PAGAMENTO TRAMITE POS", "Pagamento preautorizzato utenza").
_Avoid_: tipo operazione, codice

**Descrizione**:
Il testo libero che accompagna un movimento: nell'estratto conto lo scrive il fornitore del conto, in un movimento di cassa lo scrive chi lo inserisce; non va confusa con la nota, scritta da un utente su un movimento già registrato.
_Avoid_: dettaglio

**Import**:
Un caricamento di un estratto conto: prima un'anteprima, poi la conferma, dopo la quale i suoi movimenti entrano da classificare; si annulla per intero, e solo se nessun suo movimento è confermato. Un movimento già presente nell'app (stessa chiave) si salta e si conta. Anche la cassa contanti si importa, dal foglio di calcolo e quante volte si vuole fino alla data di passaggio: le righe sparite dal foglio si segnalano e non si cancellano mai.
_Avoid_: caricamento, upload

**Conflitto**:
Una riga dell'estratto con la stessa chiave di un movimento già presente (per la banca data operazione, importo, descrizione, saldo parziale; per PayPal e Satispay il codice transazione) ma con altri dati diversi (data valuta o causale; per PayPal e Satispay anche data, importo, commissione o descrizione, e per PayPal il saldo); non entra finché un operatore non decide se tenere il movimento presente o importare anche la riga, e non si sovrascrive mai nulla.
_Avoid_: doppione, duplicato

**Riconciliazione**:
L'accordo tra il saldo dell'app e quello dichiarato dall'estratto; il conto è riconciliato fino all'ultima data in cui i due saldi coincidono, e un buco tra due estratti lo fa arretrare. Per una cassa contanti vale l'ultima conta di cassa che concorda ancora con il saldo dell'app.
_Avoid_: quadratura

**Saldo dichiarato**:
Il saldo che l'operatore legge dal fornitore di un conto il cui estratto non ha saldi (Satispay) e scrive nell'app a una data; la differenza con il saldo dell'app si spiega, e il conto è riconciliato fino all'ultimo saldo dichiarato che coincide. Il riepilogo mensile di Satispay è invece un file di controllo, confrontato con i movimenti e mai salvato.
_Avoid_: saldo inserito, saldo manuale

**Conta di cassa**:
I contanti contati dal Tesoriere a una data, confrontati con il saldo dell'app; la differenza si spiega con un movimento di correzione (e la cassa quadra) o con una nota (e non quadra). L'ultima conta che concorda ancora con il saldo è la data di riconciliazione della cassa. Può avere due nomi (conta congiunta, per il passaggio di consegne). Una cassa si chiude solo dopo una conta e vuota, con i contanti versati come giroconto.
_Avoid_: inventario, quadratura

**Controparte**:
Chi paga o riceve il denaro di un movimento, come risulta dall'estratto o dichiarato da chi registra il movimento.
_Avoid_: beneficiario, mittente, cliente, fornitore

**Ritiro**:
L'eliminazione del proprio movimento da parte di chi l'ha inserito, possibile finché nessuno lo ha confermato e senza motivazione; è un'eliminazione logica, come quella (con motivazione) che chi ha il permesso fa su un movimento manuale anche confermato.
_Avoid_: cancellazione, annullamento (che si dice di un import)

**Giroconto**:
Un trasferimento di denaro tra due conti dell'associazione, composto da un movimento in uscita da un conto e da uno in entrata sull'altro; non è né un'entrata né un'uscita dell'ente.
_Avoid_: movimento tra conti

**Giustificativo**:
Il documento che prova un movimento (fattura, ricevuta, scontrino), indicato con un riferimento testuale ed eventualmente un collegamento al file sul Drive dell'associazione.
_Avoid_: allegato, pezza d'appoggio

### Classificazione

**Imputazione**:
La parte di un movimento attribuita a una certa area, attività, iniziativa e tipologia; un movimento ha una o più imputazioni, la cui somma è pari al suo importo.
_Avoid_: ripartizione, riga, split

**Area**:
Una delle sezioni del Modello D: attività di interesse generale (A), attività diverse (B), raccolta fondi (C), attività finanziarie e patrimoniali (D), supporto generale (E).
_Avoid_: settore, sezione, gestione

**Attività**:
Dentro un'area, l'attività in senso normativo: nell'area A un settore di interesse generale dell'art. 5 CTS, nell'area B un'attività diversa prevista dallo statuto, nell'area C una raccolta fondi abituale o occasionale.
_Avoid_: settore, ramo

**Iniziativa**:
L'evento o il progetto a cui un movimento si riferisce (es. "Festa della Torre", "Si può fare"), che dura nel tempo attraverso più esercizi e appartiene di norma a un'attività.
_Avoid_: progetto, evento, attività

**Tipologia**:
La natura di un'imputazione, indipendente dall'area (es. "Quote associative", "Acquisto di servizi", "Somministrazione").
_Avoid_: categoria, causale, nuova causale, sottovoce

**Voce Modello D**:
Una riga dello schema ministeriale del rendiconto per cassa (DM 5 marzo 2020), es. "A1) Entrate da quote associative e apporti dei fondatori"; per ogni imputazione è determinata dalla coppia area e tipologia.
_Avoid_: voce rendiconto, macro voce

**Natura commerciale**:
Il carattere commerciale o non commerciale di un'imputazione ai fini fiscali, che discende dalle sue caratteristiche e dagli esiti dei test dell'esercizio.
_Avoid_: commercialità, flag COMM

**Forzatura**:
La natura commerciale assegnata a mano a una o più imputazioni in difformità da quella che discende dalle regole, con la sua motivazione.
_Avoid_: override, eccezione

**Tag**:
Un'etichetta trasversale libera da qualunque effetto sul Modello D e sui test, usata per raggruppare imputazioni (es. "Bando Fondazione X").
_Avoid_: etichetta, parola chiave

**Regola**:
Una condizione sul movimento (conto, verso, causale, testo della descrizione, importo o intervallo, periodo; tutte da soddisfare, senza espressioni regolari) con una classificazione da proporre (tipologia, iniziativa o area e attività, socio o terzo, tag; mai la natura commerciale). Le regole sono in un elenco ordinato e vince la prima che corrisponde. Una regola propone soltanto sui movimenti *da classificare* o già *proposti da una regola*: non tocca mai i confermati né le scelte a mano.
_Avoid_: automatismo, filtro

**Tabella di conversione**:
La tabella che traduce ogni «Nuova causale» del foglio di calcolo della cassa in tipologia, area, attività, iniziativa e tag; parte da `docs/import-foglio` e il Tesoriere la modifica in una pagina dell'app finché il foglio è in uso. Una riga cambiata si riapplica ai movimenti da classificare o proposti dalla tabella; i confermati restano come sono e si elencano da correggere. Una causale assente o senza tipologia lascia il movimento da classificare, in dubbio.
_Avoid_: mappatura, conversione delle categorie

**Data di passaggio**:
L'ultimo giorno che il foglio di calcolo copre per la cassa contanti, scelto dal Tesoriere: l'import del foglio rifiuta le righe successive, così non ci sono mai due fonti per lo stesso periodo, e passata la data import e tabella di conversione si chiudono.
_Avoid_: data di migrazione, cut-off

**Manuale di classificazione**:
Il testo, nell'app, che spiega come si classificano i movimenti; diviso in sezioni dal numero stabile, che si cita come «manuale § 9», lo legge chiunque e lo modifica chi gestisce le regole, e ogni modifica lascia una versione nel registro delle modifiche. Il manuale è anche ciò che l'AI riceve quando propone una classificazione.
_Avoid_: regolamento, linee guida

### Rendicontazione

**Esercizio**:
L'anno solare a cui si riferiscono i movimenti, il rendiconto e i test.
_Avoid_: anno contabile, annualità

**Dati dell'esercizio**:
Le informazioni di un esercizio che non derivano dai movimenti, come i costi e proventi figurativi, il numero di dipendenti o il criterio scelto per il test di secondarietà.
_Avoid_: dati extra, parametri

**Esercizio storico**:
Un esercizio precedente all'avvio dell'app di cui si conoscono solo gli importi per voce Modello D e i saldi finali, senza movimenti; serve a riempire la colonna dell'esercizio precedente nel Modello D.
_Avoid_: anno precedente, esercizio importato

**Rendiconto per l'assemblea**:
Il prospetto dell'esercizio per area, attività, iniziativa e tipologia, con una colonna per conto, che il tesoriere presenta all'assemblea accanto al Modello D.
_Avoid_: rendiconto Easy, rendiconto interno

**Destinazione dell'avanzo**:
L'impiego dell'avanzo dell'esercizio proposto all'assemblea, in righe con descrizione e importo; compare solo nel rendiconto per l'assemblea.
_Avoid_: utilizzo dell'avanzo, piano spese

**Raccolta fondi occasionale**:
Un'iniziativa dell'area C svolta in occasione di una celebrazione, ricorrenza o campagna di sensibilizzazione, che ha un proprio rendiconto con relazione illustrativa, allegato al rendiconto dell'esercizio e depositato con esso. Si riconosce dall'attività dell'imputazione («Raccolta fondi occasionale»: voce C2 del Modello D; l'abituale è C1, senza allegato); la somma delle raccolte deve coincidere con la voce C2 e, dove serve, la motivazione deve esserci, altrimenti l'esercizio non si approva. Dall'approvazione i dati scritti a mano (relazione, liberalità non monetarie) non si cambiano più, perché il PDF di deposito stampa la versione approvata: per completarli si riapre l'esercizio.
_Avoid_: raccolta pubblica, evento di raccolta

**Liberalità non monetaria**:
Un bene o un servizio donato per una raccolta fondi occasionale, inserito a mano nel suo rendiconto con descrizione, quantità, valore di mercato stimato (DM 28/11/2019), criterio di stima e documento; entra solo in quel rendiconto, mai nel Modello D né nei test. Non va confusa con la tipologia «Erogazioni liberali», che è denaro.
_Avoid_: donazione in natura, offerta in natura

**Esito del test**:
Il risultato di un test previsto dal Codice del Terzo Settore in un esercizio: rispettato, vicino alla soglia o non rispettato, con il margine rispetto alla soglia; "superata" si dice solo della soglia.
_Avoid_: test superato, test non superato, test passato

**Annotazione in calce**:
Il testo in calce al rendiconto con le dichiarazioni dovute (criterio di secondarietà, strumentalità, criterio di riparto dei costi promiscui), precompilato dagli esiti dei test (segue i dati finché il Tesoriere non lo corregge); non va confusa con la nota di un movimento.
_Avoid_: nota, commento

**Approvazione**:
La delibera dell'assemblea che approva il rendiconto di un esercizio. Nell'app la registra il Tesoriere con la data della delibera: salva la **versione approvata** dei prospetti e dei test e fissa il criterio di secondarietà, che non cambia più nemmeno dopo una riapertura. Un esercizio si approva solo dopo quello precedente. Dopo, le modifiche ai movimenti sono ammesse con avviso e i prospetti (Modello D, rendiconto per l'assemblea, test) mostrano le differenze dalla versione approvata.
_Avoid_: chiusura

**Riapertura**:
Il ritorno di un esercizio approvato ad aperto, solo dell'Amministratore e con motivazione, per correggere un errore: la versione approvata resta nello storico e la nuova approvazione è la versione successiva. Si riapre in ordine inverso a quello di approvazione: un esercizio non si riapre finché quello dopo è approvato. Una secondarietà non rispettata approvata non si dimentica: rientro dell'anno dopo e segnalazione al RUNTS restano anche se la nuova versione ha un altro esito.
_Avoid_: annullamento dell'approvazione

**Deposito**:
L'invio al RUNTS del rendiconto approvato; da quel momento il rendiconto dell'esercizio è fissato, salvo riapertura per una nuova approvazione. Nell'app il Tesoriere scarica il **PDF di deposito** dall'esercizio approvato e inserisce la data dell'invio: l'esercizio passa a *depositato*, si salva l'**istantanea del deposito** e i movimenti non si toccano più (nemmeno classificandoli, confermandoli o importando); note e menzioni restano possibili.
_Avoid_: invio, pubblicazione

**PDF di deposito**:
L'unico PDF/A-1b da firmare e inviare al RUNTS: indice in prima pagina e Modello D (poi gli allegati delle raccolte). Parte dalla versione approvata, mai dai dati di oggi, e il suo nome (`Deposito_<anno>_<Ente>.pdf`) non è quello dei PDF dei prospetti. Per correggere dopo l'approvazione si riapre l'esercizio e si approva di nuovo.
_Avoid_: PDF definitivo

**Istantanea del deposito**:
Ciò che il PDF di deposito stampa, salvato sulla versione al momento della data dell'invio e mai più cambiato: da lì si leggono Modello D, rendiconto per l'assemblea, test ed esportazioni di un esercizio depositato, identici al PDF. La riapertura la lascia nello storico con la sua versione.
_Avoid_: copia, fotografia

**Preventivo**:
Le entrate e le uscite previste per un'iniziativa in un esercizio, da confrontare con quelle effettive.
_Avoid_: budget, bilancio di previsione

### Accesso e comunicazione

**Ruolo**:
Un insieme di permessi con un nome (es. Tesoriere, Consigliere), creato e modificato dall'Amministratore.
_Avoid_: profilo, gruppo

**Permesso**:
Una singola cosa che l'app consente di fare (es. inserire movimenti di cassa, approvare l'esercizio), scelta da un elenco fisso.
_Avoid_: diritto, autorizzazione

**Assegnazione**:
Un ruolo dato a una persona, con un ambito e una scadenza facoltativi; una persona può avere più assegnazioni e i loro permessi si sommano.
_Avoid_: abilitazione

**Account esterno**:
Un account Google personale (non del Workspace), aggiunto uno per uno dall'Amministratore a un consulente come il commercialista o il revisore. Resta in sola lettura qualunque ruolo abbia (vede, scrive note e, se il ruolo lo prevede, consulta registro e storia dei movimenti), ha sempre una scadenza e, quando c'è, la data della nomina firmata.
_Avoid_: ospite, utente esterno

**Account di emergenza**:
L'unico account locale, con password lunga e codice a tempo, con cui si entra quando Google non funziona o non resta nessun Amministratore; ha i permessi di un Amministratore ma non compare tra gli utenti né ha assegnazioni, la sua sessione dura un'ora e ogni suo uso finisce nel registro degli accessi e avvisa gli Amministratori.
_Avoid_: account di servizio, super utente, backdoor

**Riautenticazione**:
Il rifare l'accesso (con Google, o con password e codice per l'account di emergenza) che le azioni delicate chiedono quando l'ultimo accesso risale a più di un'ora fa.
_Avoid_: conferma della password, nuovo login

**Elenco degli autorizzati**:
Le assegnazioni in corso (persona, ruolo, conti, scadenza, nomina firmata), esportate in PDF datato come elenco di chi può trattare i dati personali; non include le assegnazioni scadute.
_Avoid_: elenco utenti

**Ambito**:
I conti su cui valgono i permessi di un'assegnazione (es. solo la "Cassa Festa"); limita ciò che si può scrivere o proporre, non ciò che si vede.
_Avoid_: perimetro, scope

**Nota**:
Un messaggio breve di un utente su un movimento, con autore e data, senza effetto sul Modello D, sui test o sulla classificazione.
_Avoid_: commento, annotazione

**Menzione**:
Il richiamo `@utente` o `@ruolo` dentro una nota, che chiede l'attenzione di qualcuno finché non lo segna come fatto; non va confuso con il tag.
_Avoid_: tag, notifica

**Compito periodico**:
Un comando che parte da solo a una scadenza (la pulizia degli spazi di prova, il riepilogo email delle menzioni alle 8, il backup mensile); su Aruba lo avvia la chiamata HTTP del cron, protetta da un token.
_Avoid_: job, cron job

**Dubbio**:
Una nota contrassegnata che segnala un movimento da verificare; il movimento resta in dubbio finché la nota è aperta, e la conferma la chiude.
_Avoid_: sospeso, da verificare

**Tesoriere in carica**:
La persona che risponde della tesoreria in un dato momento, una sola alla volta; è distinta dal ruolo Tesoriere, che più persone possono avere, e cambia solo con un passaggio di consegne.
_Avoid_: tesoriere attivo, tesoriere principale

**Passaggio di consegne**:
Il trasferimento della tesoreria dal tesoriere in carica al successivo, avviato da un Amministratore, firmato dall'uscente alla consegna e dal nuovo alla presa in carico, e fissato in un'istantanea alla data della consegna.
_Avoid_: cambio tesoriere, handover, consegna

**Riserva**:
Un punto del passaggio di consegne che il nuovo tesoriere non accetta così com'è e che resta aperto finché non lo scioglie.
_Avoid_: eccezione, nota, contestazione

**Lettera di consegna**:
Il testo libero con cui il tesoriere uscente spiega al successore ciò che i dati non dicono, allegato al passaggio di consegne.
_Avoid_: note di consegna, promemoria

**Elemento esterno**:
Una cosa fuori dall'app da passare al nuovo tesoriere (es. firma sul conto, accesso a PayPal, cassa fisica), di cui l'app registra solo lo stato e mai le credenziali.
_Avoid_: accesso, credenziale

### Onboarding

**Missione**:
Un breve esercizio guidato sulle schermate vere dell'app, svolto nello spazio di prova, che insegna una cosa sola e richiede i permessi di quella cosa.
_Avoid_: lezione, tutorial, livello

**Percorso**:
Un elenco ordinato di missioni pensato per chi ha un certo ruolo (es. il percorso del Tesoriere); raggruppa le missioni ma non decide chi può farle.
_Avoid_: corso, livello, ruolo

**Spazio di prova**:
L'insieme personale di dati finti di un utente, separato dai dati veri, in cui si svolgono le missioni; non entra mai nei report, nei test, nei backup o nel registro.
_Avoid_: sandbox, demo, esercizio di prova

**Scenario**:
La situazione di partenza di una missione, con conti, movimenti ed estratti inventati, da cui lo spazio di prova riparte a ogni avvio.
_Avoid_: dati di esempio, fixture

**Timbro**:
Il segno che un utente riceve completando una missione; una missione saltata non lo dà.
_Avoid_: badge, premio, punti

**Sigillo**:
Il segno che un utente riceve completando tutte le missioni di un percorso.
_Avoid_: badge, trofeo

### Dati personali

**Anonimizzazione**:
La rimozione dei dati personali da un esercizio dieci anni dopo la sua fine, che ne tiene importi e classificazione e sostituisce le persone con il loro tipo ("Socio", "Privato"); la propone l'app e la conferma l'Amministratore.
_Avoid_: cancellazione, pulizia, scadenza

**Registro delle modifiche**:
L'elenco in sola aggiunta di chi ha cambiato cosa e quando, con il valore prima e dopo, per movimenti, conti e il resto dei dati dell'app; il registro completo è per il Tesoriere e l'Amministratore, la storia del singolo movimento per tutti i ruoli interni. Non va confuso con il registro degli accessi.
_Avoid_: log, cronologia, audit

**Registro degli accessi**:
L'elenco di chi è entrato nell'app, quando e da dove, e di ogni esportazione fatta; serve a ricostruire un incidente e si conserva dodici mesi. Non va confuso con il registro delle modifiche.
_Avoid_: log, registro attività

**Backup**:
L'esportazione completa dell'app (database ricostruibile e un CSV leggibile per tabella) caricata sul drive condiviso: parte da sola il primo del mese o la chiede un Amministratore. Si tengono, per i mensili e a parte per quelli su richiesta (che non tolgono mai un mensile), gli ultimi 12 mesi e l'ultimo di ogni anno, per 10 anni. Non contiene lo spazio di prova, le sessioni né i segreti.
_Avoid_: copia, dump, salvataggio

**Registro degli errori**:
L'elenco degli errori dell'app: un errore uguale e ancora aperto si conta invece di ripetersi, un Amministratore lo segna come risolto, e quelli *gravi* (un backup fallito, un compito periodico che si ferma, un'eccezione non gestita) avvisano gli Amministratori per email, al massimo una volta al giorno. Non va confuso con il registro delle modifiche né con quello degli accessi.
_Avoid_: log, registro degli incidenti

**Stato del sistema**:
Il riquadro dell'Amministratore con la salute dell'app: ultimo backup, ultimo import e riconciliazione per conto, errori aperti, Amministratori. Le integrazioni esterne (drive condiviso, posta, Google) sono facoltative: se una non risponde l'app va avanti e lo stato del sistema lo mostra.
_Avoid_: cruscotto, monitoraggio

**Versione senza nomi**:
Un'esportazione dei movimenti in cui le controparti persone fisiche sono sostituite dal loro tipo e mancano note e descrizione.
_Avoid_: versione anonima, export anonimizzato
