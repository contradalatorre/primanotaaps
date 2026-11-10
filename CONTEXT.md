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

**Causale**:
L'etichetta breve con cui il fornitore del conto indica il tipo di operazione (es. "PAGAMENTO TRAMITE POS", "Pagamento preautorizzato utenza").
_Avoid_: tipo operazione, codice

**Descrizione**:
Il testo libero che accompagna un movimento: nell'estratto conto lo scrive il fornitore del conto, in un movimento di cassa lo scrive chi lo inserisce; non va confusa con la nota, scritta da un utente su un movimento già registrato.
_Avoid_: dettaglio

**Import**:
Un caricamento di un estratto conto: prima un'anteprima, poi la conferma, dopo la quale i suoi movimenti entrano da classificare; si annulla per intero, e solo se nessun suo movimento è confermato. Un movimento già presente nell'app (stessa chiave) si salta e si conta.
_Avoid_: caricamento, upload

**Conflitto**:
Una riga dell'estratto con la stessa chiave di un movimento già presente (data operazione, importo, descrizione, saldo parziale) ma con data valuta o causale diverse; non entra finché un operatore non decide se tenere il movimento presente o importare anche la riga, e non si sovrascrive mai nulla.
_Avoid_: doppione, duplicato

**Riconciliazione**:
L'accordo tra il saldo dell'app e quello dichiarato dall'estratto; il conto è riconciliato fino all'ultima data in cui i due saldi coincidono, e un buco tra due estratti lo fa arretrare.
_Avoid_: quadratura

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
Un'iniziativa dell'area C svolta in occasione di una celebrazione, ricorrenza o campagna di sensibilizzazione, che ha un proprio rendiconto con relazione illustrativa, allegato al rendiconto dell'esercizio e depositato con esso.
_Avoid_: raccolta pubblica, evento di raccolta

**Esito del test**:
Il risultato di un test previsto dal Codice del Terzo Settore in un esercizio: rispettato, vicino alla soglia o non rispettato, con il margine rispetto alla soglia; "superata" si dice solo della soglia.
_Avoid_: test superato, test non superato, test passato

**Approvazione**:
La delibera dell'assemblea che approva il rendiconto di un esercizio.
_Avoid_: chiusura

**Deposito**:
L'invio al RUNTS del rendiconto approvato; da quel momento il rendiconto dell'esercizio è fissato, salvo riapertura per una nuova approvazione.
_Avoid_: invio, pubblicazione

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
Un comando che parte da solo a una scadenza (la pulizia degli spazi di prova, il riepilogo email delle menzioni alle 8); su Aruba lo avvia la chiamata HTTP del cron, protetta da un token.
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

**Versione senza nomi**:
Un'esportazione dei movimenti in cui le controparti persone fisiche sono sostituite dal loro tipo e mancano note e descrizione.
_Avoid_: versione anonima, export anonimizzato
