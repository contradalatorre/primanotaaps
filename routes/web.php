<?php

use App\Http\Controllers\AccessoController;
use App\Http\Controllers\AperturaSaldiController;
use App\Http\Controllers\ApprovazioneController;
use App\Http\Controllers\ClassificazioneController;
use App\Http\Controllers\ConteDiCassaController;
use App\Http\Controllers\ContiController;
use App\Http\Controllers\ConversioneCausaliController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DatiDiClassificazioneController;
use App\Http\Controllers\DatiEsercizioController;
use App\Http\Controllers\EmergenzaController;
use App\Http\Controllers\EsercizioController;
use App\Http\Controllers\EsercizioStoricoController;
use App\Http\Controllers\EsportazioniController;
use App\Http\Controllers\ForzatureController;
use App\Http\Controllers\GirocontiController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ImportEstrattiController;
use App\Http\Controllers\ImpostazioniController;
use App\Http\Controllers\IniziativeController;
use App\Http\Controllers\ManualeController;
use App\Http\Controllers\MigrazioniController;
use App\Http\Controllers\MovimentiController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PreferenzeController;
use App\Http\Controllers\RaccolteController;
use App\Http\Controllers\RegistroController;
use App\Http\Controllers\RegoleController;
use App\Http\Controllers\RevisioneImportController;
use App\Http\Controllers\RuoliController;
use App\Http\Controllers\SatispayController;
use App\Http\Controllers\StatoSistemaController;
use App\Http\Controllers\UtentiController;
use App\Http\Controllers\VerificheController;
use App\Http\Middleware\ProvaMiddleware;
use App\Http\Middleware\VerificaAccountGoogle;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware('guest')->group(function () {
    Route::get('/accesso', [AccessoController::class, 'pagina'])->name('accesso');
    Route::get('/accesso/emergenza', [EmergenzaController::class, 'pagina'])->name('accesso.emergenza');
    // 5 tentativi ogni 10 minuti per indirizzo: password lunga e TOTP reggono, ma il registro non si riempia di prove.
    Route::post('/accesso/emergenza', [EmergenzaController::class, 'entra'])->middleware('throttle:5,10');
});

// Fuori da `guest`: chi è già dentro ci passa per rifare l'accesso (#36); il controller rimanda a casa chi arriva
// senza averlo chiesto.
Route::get('/accesso/google', [AccessoController::class, 'vaiAGoogle'])->name('accesso.google');
Route::get('/accesso/google/ritorno', [AccessoController::class, 'ritornoDaGoogle'])->name('accesso.ritorno');

Route::middleware(['auth', VerificaAccountGoogle::class, ProvaMiddleware::class])->group(function () {
    Route::get('/', [HomeController::class, 'index'])->name('home');
    Route::post('/esci', [AccessoController::class, 'esci'])->name('esci');
    Route::get('/preferenze', [PreferenzeController::class, 'index'])->name('preferenze');
    Route::put('/preferenze', [PreferenzeController::class, 'salva'])->name('preferenze.salva');
    Route::get('/riautentica', [AccessoController::class, 'riautentica'])->name('riautentica');

    // Conti e movimenti (#38). Leggere è per tutti: l'ambito limita la scrittura, non la lettura. Dove il permesso
    // ha un ambito il conto è nell'indirizzo (`can:permesso,conto`); per i movimenti già scritti il controller
    // controlla il conto del movimento.
    Route::middleware('can:vedere')->group(function () {
        Route::get('/conti', [ContiController::class, 'index'])->name('conti');
        Route::get('/movimenti', [MovimentiController::class, 'index'])->name('movimenti');
        Route::get('/classificazione', [DatiDiClassificazioneController::class, 'index'])->name('classificazione');
        Route::get('/manuale', [ManualeController::class, 'index'])->name('manuale');
        Route::get('/manuale/esporta', [ManualeController::class, 'esporta'])->name('manuale.esporta');
        // Il Modello D a schermo (#51), sempre provvisorio; ogni cifra porta all'elenco delle sue imputazioni.
        Route::get('/modello-d', [EsercizioController::class, 'indice'])->name('modello-d.indice');
        Route::get('/esercizi/{anno}/modello-d', [EsercizioController::class, 'modelloD'])->where('anno', '[1-9][0-9]{3}')->name('modello-d');
        Route::get('/esercizi/{anno}/imputazioni', [EsercizioController::class, 'imputazioni'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.imputazioni');
        Route::get('/esercizi/{anno}/forzature', [ForzatureController::class, 'indice'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.forzature'); // #52
        // Conti per iniziativa con preventivo e scostamento (#57).
        Route::get('/esercizi/{anno}/iniziative', [IniziativeController::class, 'conti'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.iniziative');
        // Le raccolte fondi occasionali (#61): l'elenco con la quadratura con C2 e il rendiconto con la relazione di ognuna.
        Route::get('/esercizi/{anno}/raccolte', [RaccolteController::class, 'indice'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.raccolte');
        Route::get('/esercizi/{anno}/raccolte/{iniziativa}', [RaccolteController::class, 'mostra'])->where(['anno' => '[1-9][0-9]{3}', 'iniziativa' => '[0-9]+'])->name('esercizio.raccolta');
        // Il rendiconto per l'assemblea (#56): dal vivo, senza nomi di persone.
        Route::get('/esercizi/{anno}/rendiconto', [EsercizioController::class, 'rendiconto'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.rendiconto');
        // Le esportazioni dei prospetti (#58): stessi permessi delle pagine da cui nascono; il controller toglie ciò che chi scarica non vedrebbe.
        Route::middleware('throttle:30,1')->where(['anno' => '[1-9][0-9]{3}', 'formato' => 'pdf|xlsx', 'tipo' => 'imputazioni|movimenti'])->group(function () {
            Route::get('/esercizi/{anno}/modello-d/{formato}', [EsportazioniController::class, 'modelloD'])->name('esporta.modello-d');
            Route::get('/esercizi/{anno}/rendiconto/{formato}', [EsportazioniController::class, 'rendiconto'])->name('esporta.rendiconto');
            Route::get('/esercizi/{anno}/deposito.pdf', [EsportazioniController::class, 'deposito'])->name('esporta.deposito');
            Route::get('/esercizi/{anno}/raccolte/{iniziativa}/{formato}', [EsportazioniController::class, 'raccolta'])->where('iniziativa', '[0-9]+')->name('esporta.raccolta'); // #61: ogni raccolta da sola
            Route::get('/esercizi/{anno}/{tipo}.csv', [EsportazioniController::class, 'elenco'])->name('esporta.elenco');
        });
    });
    // Approvazione e riapertura dell'esercizio (#59). Il permesso è fuori e `password.confirm:riautentica` dentro (come per gli utenti): senza il permesso è un 403, non un invito a riautenticarsi.
    Route::middleware('can:vedere')->get('/esercizi/{anno}/approvazione', [ApprovazioneController::class, 'pagina'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.approvazione');
    Route::middleware('can:gestire_esercizio')->where(['anno' => '[1-9][0-9]{3}'])->group(function () {
        Route::post('/esercizi/{anno}/approvazione', [ApprovazioneController::class, 'approva'])->middleware('password.confirm:riautentica')->name('esercizio.approva');
        // Inserire la data dell'invio al RUNTS fissa l'esercizio (#60): azione delicata, come approvare.
        Route::post('/esercizi/{anno}/deposito', [ApprovazioneController::class, 'deposita'])->middleware('password.confirm:riautentica')->name('esercizio.deposita');
        Route::post('/esercizi/{anno}/segnalazione-runts', [ApprovazioneController::class, 'segnala'])->name('esercizio.segnalazione');
    });
    Route::post('/esercizi/{anno}/riapertura', [ApprovazioneController::class, 'riapri'])->where('anno', '[1-9][0-9]{3}')
        ->middleware(['can:riaprire_esercizio', 'password.confirm:riautentica'])->name('esercizio.riapri');
    // Forzare la natura commerciale (#52) è di chi classifica; App\Forzature rilegge il permesso a riga bloccata.
    Route::post('/imputazioni/natura', [ForzatureController::class, 'forza'])->middleware('can:importare_e_confermare')->name('natura.forza');
    Route::post('/imputazioni/natura/toglie', [ForzatureController::class, 'togli'])->middleware('can:importare_e_confermare')->name('natura.togli');
    // Test dell'esercizio (#53): provvisori, dal vivo. Confermare il criterio e scrivere i dati dell'esercizio sono di `gestire_esercizio` (#16);
    // la pagina dei dati lo richiede anche per leggere: le descrizioni dei figurativi possono nominare persone (storia 143).
    Route::middleware('can:vedere')->group(function () {
        Route::get('/test', [VerificheController::class, 'indice'])->name('test-esercizio.indice');
        Route::get('/esercizi/{anno}/test', [VerificheController::class, 'mostra'])->where('anno', '[1-9][0-9]{3}')->name('test-esercizio');
    });
    Route::middleware('can:gestire_esercizio')->where(['anno' => '[1-9][0-9]{3}', 'riga' => '[0-9]+'])->group(function () {
        Route::post('/esercizi/{anno}/test/criterio', [VerificheController::class, 'confermaCriterio'])->name('test-esercizio.criterio');
        Route::put('/esercizi/{anno}/test/annotazione', [VerificheController::class, 'annotazione'])->name('test-esercizio.annotazione'); // #54
        // La procedura di apertura (#63): i saldi dei conti al 1/1, una sola volta. L'URL non è «apertura»: quello è la copia delle voci (#51).
        Route::get('/esercizi/{anno}/saldi-apertura', [AperturaSaldiController::class, 'indice'])->name('esercizio.saldi');
        Route::post('/esercizi/{anno}/saldi-apertura', [AperturaSaldiController::class, 'inserisci'])->name('esercizio.saldi.inserisci');
        Route::get('/esercizi/{anno}/dati', [DatiEsercizioController::class, 'indice'])->name('dati-esercizio');
        Route::put('/esercizi/{anno}/dati', [DatiEsercizioController::class, 'salva'])->name('dati-esercizio.salva');
        Route::post('/esercizi/{anno}/dati/figurativi', [DatiEsercizioController::class, 'aggiungiFigurativo'])->name('dati-esercizio.figurativi.aggiungi');
        Route::delete('/esercizi/{anno}/dati/figurativi/{riga}', [DatiEsercizioController::class, 'eliminaFigurativo'])->name('dati-esercizio.figurativi.elimina');
        Route::post('/esercizi/{anno}/dati/destinazione', [DatiEsercizioController::class, 'aggiungiDestinazione'])->name('dati-esercizio.destinazione.aggiungi'); // #56
        Route::delete('/esercizi/{anno}/dati/destinazione/{riga}', [DatiEsercizioController::class, 'eliminaDestinazione'])->name('dati-esercizio.destinazione.elimina');
        // I dati a mano di una raccolta fondi occasionale e le sue liberalità non monetarie (#61).
        Route::put('/esercizi/{anno}/raccolte/{iniziativa}', [RaccolteController::class, 'salva'])->where('iniziativa', '[0-9]+')->name('esercizio.raccolta.salva');
        Route::post('/esercizi/{anno}/raccolte/{iniziativa}/liberalita', [RaccolteController::class, 'aggiungiLiberalita'])->where('iniziativa', '[0-9]+')->name('esercizio.raccolta.liberalita.aggiungi');
        Route::delete('/esercizi/{anno}/raccolte/{iniziativa}/liberalita/{riga}', [RaccolteController::class, 'eliminaLiberalita'])->where('iniziativa', '[0-9]+')->name('esercizio.raccolta.liberalita.elimina');
    });
    // Il preventivo di un'iniziativa lo scrive chi gestisce regole, tipologie e iniziative (#16).
    Route::middleware('can:gestire_regole')->where(['anno' => '[1-9][0-9]{3}', 'iniziativa' => '[0-9]+'])->group(function () {
        Route::get('/esercizi/{anno}/iniziative/{iniziativa}/preventivo', [IniziativeController::class, 'preventivo'])->name('esercizio.preventivo');
        Route::put('/esercizi/{anno}/iniziative/{iniziativa}/preventivo', [IniziativeController::class, 'salva'])->name('esercizio.preventivo.salva');
    });
    // Aprire un esercizio nuovo copia voci e predefiniti: è «modificare la tabella delle voci» (#16).
    Route::post('/esercizi/{anno}/apertura', [EsercizioController::class, 'apri'])->where('anno', '[1-9][0-9]{3}')->middleware('can:riclassificare_in_blocco')->name('esercizio.apri');
    // L'esercizio storico dal foglio approvato (#63, #18): lo vede chi vede, lo carica chi gestisce i dati dell'esercizio, lo modifica solo chi può riaprirlo.
    Route::middleware('can:vedere')->get('/esercizi/{anno}/storico', [EsercizioStoricoController::class, 'indice'])->where('anno', '[1-9][0-9]{3}')->name('esercizio.storico');
    Route::middleware('can:gestire_esercizio')->where(['anno' => '[1-9][0-9]{3}', 'token' => '[A-Za-z0-9]{40}'])->group(function () {
        Route::post('/esercizi/{anno}/storico', [EsercizioStoricoController::class, 'carica'])->name('esercizio.storico.carica');
        Route::get('/esercizi/{anno}/storico/anteprima/{token}', [EsercizioStoricoController::class, 'anteprima'])->name('esercizio.storico.anteprima');
        Route::post('/esercizi/{anno}/storico/anteprima/{token}', [EsercizioStoricoController::class, 'conferma'])->name('esercizio.storico.conferma');
    });
    Route::middleware('can:riaprire_esercizio')->where(['anno' => '[1-9][0-9]{3}'])->group(function () {
        Route::get('/esercizi/{anno}/storico/modifica', [EsercizioStoricoController::class, 'modifica'])->name('esercizio.storico.modifica');
        Route::put('/esercizi/{anno}/storico/modifica', [EsercizioStoricoController::class, 'aggiorna'])->name('esercizio.storico.aggiorna');
    });
    // I dati di base (conti) sono di chi ha `gestire_regole`: #16 non ha un permesso a parte.
    Route::middleware('can:gestire_regole')->group(function () {
        Route::get('/conti/nuovo', [ContiController::class, 'nuovo'])->name('conti.nuovo');
        Route::post('/conti', [ContiController::class, 'crea'])->name('conti.crea');
        Route::get('/conti/{conto}/modifica', [ContiController::class, 'modifica'])->name('conti.modifica');
        Route::put('/conti/{conto}', [ContiController::class, 'salva'])->name('conti.salva');
        Route::post('/conti/{conto}/chiusura', [ContiController::class, 'chiudi'])->name('conti.chiudi');
    });
    // Tipologie, attività, iniziative e tag (#39): anche questi sono dati di base, quindi `gestire_regole`.
    Route::middleware('can:gestire_regole')->where(['tipo' => 'tipologie|attivita|iniziative|tag', 'id' => '[0-9]+'])->group(function () {
        Route::post('/classificazione/{tipo}', [DatiDiClassificazioneController::class, 'crea'])->name('classificazione.crea');
        Route::put('/classificazione/{tipo}/{id}', [DatiDiClassificazioneController::class, 'salva'])->name('classificazione.salva');
        Route::post('/classificazione/{tipo}/{id}/archiviazione', [DatiDiClassificazioneController::class, 'archivia'])->name('classificazione.archivia');
        Route::post('/classificazione/{tipo}/{id}/ripristino', [DatiDiClassificazioneController::class, 'ripristina'])->name('classificazione.ripristina');
        Route::delete('/classificazione/{tipo}/{id}', [DatiDiClassificazioneController::class, 'elimina'])->name('classificazione.elimina');
    });
    // Manuale di classificazione (#44): scriverlo è di chi gestisce le regole (#16). Le sezioni non si cancellano.
    Route::middleware('can:gestire_regole')->group(function () {
        Route::post('/manuale', [ManualeController::class, 'crea'])->name('manuale.crea');
        Route::put('/manuale/{sezione}', [ManualeController::class, 'salva'])->name('manuale.salva');
    });
    // Regole del tesoriere (#42): `gestire_regole`, come il manuale. Il riordino e il salvataggio rifanno le proposte.
    // La tabella di conversione delle causali del foglio (#64): la vede chi vede, la modifica chi gestisce i dati di base, finché il foglio è in uso.
    Route::middleware('can:vedere')->get('/conversione-causali', [ConversioneCausaliController::class, 'indice'])->name('conversione');
    Route::middleware('can:gestire_regole')->where(['riga' => '[0-9]+'])->group(function () {
        Route::post('/conversione-causali', [ConversioneCausaliController::class, 'crea'])->name('conversione.crea');
        Route::put('/conversione-causali/{riga}', [ConversioneCausaliController::class, 'salva'])->name('conversione.salva');
    });
    Route::middleware('can:gestire_regole')->group(function () {
        Route::get('/regole', [RegoleController::class, 'index'])->name('regole');
        Route::get('/regole/nuova', [RegoleController::class, 'nuova'])->name('regole.nuova');
        Route::post('/regole', [RegoleController::class, 'crea'])->name('regole.crea');
        Route::match(['post', 'put'], '/regole/anteprima', [RegoleController::class, 'anteprima'])->name('regole.anteprima');
        Route::post('/regole/ordine', [RegoleController::class, 'ordina'])->name('regole.ordine');
        Route::get('/regole/{regola}/modifica', [RegoleController::class, 'modifica'])->name('regole.modifica');
        Route::put('/regole/{regola}', [RegoleController::class, 'salva'])->name('regole.salva');
    });
    Route::middleware('can:inserire_movimenti_cassa,conto')->group(function () {
        Route::get('/conti/{conto}/movimenti/nuovo', [MovimentiController::class, 'nuovo'])->name('movimenti.nuovo');
        Route::post('/conti/{conto}/movimenti', [MovimentiController::class, 'crea'])->name('movimenti.crea');
    });
    // Conta di cassa (#50): la storia la vedono tutti, contare è del Tesoriere (il permesso si rifà a conto bloccato).
    Route::get('/conti/{conto}/conte', [ConteDiCassaController::class, 'index'])->middleware('can:vedere')->name('conti.conte');
    Route::post('/conti/{conto}/conte', [ConteDiCassaController::class, 'registra'])->middleware('can:importare_e_confermare')->name('conti.conte.registra');
    // Import degli estratti (#40). `importare_e_confermare` non ha ambito (#16): niente conto da passare.
    Route::middleware('can:importare_e_confermare')->group(function () {
        Route::get('/conti/{conto}/import', [ImportEstrattiController::class, 'index'])->name('import.indice');
        Route::post('/conti/{conto}/import', [ImportEstrattiController::class, 'carica'])->name('import.carica');
        Route::post('/conti/{conto}/passaggio', [ImportEstrattiController::class, 'passaggio'])->name('import.passaggio'); // la data di passaggio dal foglio (#64)
        // Conti senza saldo nell'estratto (Satispay, #49): riepilogo mensile di controllo e saldo dichiarato.
        Route::post('/conti/{conto}/riepilogo', [SatispayController::class, 'riepilogo'])->name('import.riepilogo');
        Route::post('/conti/{conto}/saldi-dichiarati', [SatispayController::class, 'saldoDichiarato'])->name('import.saldo');
        Route::get('/import/{import}', [ImportEstrattiController::class, 'mostra'])->name('import.mostra');
        // Revisione dei movimenti di un import (#41)
        Route::get('/import/{import}/revisione', [RevisioneImportController::class, 'mostra'])->name('import.revisione');
        Route::post('/import/{import}/conferme', [RevisioneImportController::class, 'conferma'])->name('import.revisione.conferma');
        Route::post('/import/{import}/conferme-pronte', [RevisioneImportController::class, 'confermaPronte'])->name('import.revisione.conferma-pronte');
        Route::post('/import/{import}/iniziativa', [RevisioneImportController::class, 'iniziativa'])->name('import.revisione.iniziativa');
        // Giroconti (#47): abbinamento proposto, metà proposta, scioglimento. Il permesso è lo stesso della conferma.
        Route::post('/import/{import}/giroconti', [GirocontiController::class, 'conferma'])->name('import.giroconti.conferma');
        Route::post('/import/{import}/giroconti/meta', [GirocontiController::class, 'meta'])->name('import.giroconti.meta');
        Route::post('/import/{import}/giroconti/{giroconto}/scioglimento', [GirocontiController::class, 'scioglie'])->name('import.giroconti.scioglie');
        Route::post('/import/{import}/conferma', [ImportEstrattiController::class, 'conferma'])->name('import.conferma');
        Route::post('/import/{import}/scarto', [ImportEstrattiController::class, 'scarta'])->name('import.scarta');
        Route::post('/import/{import}/annullamento', [ImportEstrattiController::class, 'annulla'])->name('import.annulla');
        Route::post('/import/conflitti/{conflitto}/decisione', [ImportEstrattiController::class, 'decidi'])->name('import.decidi');
    });
    Route::get('/movimenti/{movimento}/modifica', [MovimentiController::class, 'modifica'])->name('movimenti.modifica');
    Route::put('/movimenti/{movimento}', [MovimentiController::class, 'salva'])->name('movimenti.salva');
    // Classificazione (#39): il Tesoriere sempre, l'autore sul proprio movimento non confermato (controllo nel controllore).
    Route::get('/movimenti/{movimento}/classificazione', [ClassificazioneController::class, 'modifica'])->name('movimenti.classificazione');
    Route::put('/movimenti/{movimento}/classificazione', [ClassificazioneController::class, 'salva'])->name('movimenti.classificazione.salva');
    Route::put('/movimenti/{movimento}/classificazione/riga', [ClassificazioneController::class, 'aggiungiRiga'])->name('movimenti.classificazione.riga'); // PUT: il form porta _method=PUT
    Route::post('/movimenti/{movimento}/giustificativi', [ClassificazioneController::class, 'aggiungiGiustificativo'])->name('movimenti.giustificativi');
    Route::delete('/giustificativi/{giustificativo}', [ClassificazioneController::class, 'rimuoviGiustificativo'])->name('giustificativi.elimina');
    Route::post('/movimenti/{movimento}/conferma', [ClassificazioneController::class, 'conferma'])
        ->middleware('can:importare_e_confermare')->name('movimenti.conferma');
    Route::post('/movimenti/{movimento}/ritiro', [MovimentiController::class, 'ritira'])->name('movimenti.ritira');
    Route::post('/movimenti/{movimento}/eliminazione', [MovimentiController::class, 'elimina'])
        ->middleware('can:eliminare_movimento_manuale')->name('movimenti.elimina');
    Route::get('/movimenti/{movimento}/storia', [MovimentiController::class, 'storia'])
        ->middleware('can:vedere_storia_movimento')->withTrashed()->name('movimenti.storia');
    // Note, dubbi e menzioni (#45). Leggere e scrivere è di chi ha `note` (e vede il movimento), anche fuori dall'ambito:
    // niente conto nella rotta. Il controllo si rifà a riga bloccata in `App\Note`.
    Route::middleware(['can:vedere', 'can:note'])->group(function () {
        Route::get('/movimenti/{movimento}/note', [NoteController::class, 'indice'])->name('movimenti.note');
        Route::post('/movimenti/{movimento}/note', [NoteController::class, 'crea'])->name('movimenti.note.crea');
        Route::put('/note/{nota}', [NoteController::class, 'modifica'])->name('note.modifica');
        Route::delete('/note/{nota}', [NoteController::class, 'elimina'])->name('note.elimina');
        Route::post('/menzioni/{menzione}/fatto', [NoteController::class, 'fatto'])->name('menzioni.fatto');
    });
    Route::post('/note/{nota}/oscuramento', [NoteController::class, 'oscura'])->middleware('can:oscurare_nota')->name('note.oscura');
    Route::post('/movimenti/{movimento}/dubbio/chiusura', [NoteController::class, 'togliDubbio'])
        ->middleware('can:importare_e_confermare')->name('movimenti.dubbio.chiudi');
    Route::get('/registro', [RegistroController::class, 'index'])
        ->middleware('can:vedere_registro_modifiche')->name('registro');

    // Stato del sistema e backup su richiesta (#68): `gestire_impostazioni` (#16, «Impostazioni, automatismo AI, backup»).
    Route::middleware('can:gestire_impostazioni')->group(function () {
        Route::get('/sistema', [StatoSistemaController::class, 'index'])->name('sistema');
        Route::post('/sistema/backup', [StatoSistemaController::class, 'backup'])->name('sistema.backup');
        Route::post('/sistema/errori/{errore}/risoluzione', [StatoSistemaController::class, 'risolvi'])->name('sistema.errori.risolvi');
        // Impostazioni dell'associazione con validità per esercizio (#51).
        Route::get('/impostazioni', [ImpostazioniController::class, 'index'])->name('impostazioni');
        Route::put('/impostazioni', [ImpostazioniController::class, 'salva'])->name('impostazioni.salva');
    });

    // Gestione di utenti, ruoli e assegnazioni (#35). Le scritture sono azioni delicate (#36): `password.confirm`
    // (timeout in config/auth.php, un'ora) manda a /riautentica chi ha l'ultimo accesso più vecchio.
    Route::middleware('can:gestire_utenti')->group(function () {
        Route::get('/utenti', [UtentiController::class, 'index'])->name('utenti');
        Route::get('/utenti/autorizzati.pdf', [UtentiController::class, 'elencoPdf'])->name('utenti.pdf');
        Route::get('/ruoli', [RuoliController::class, 'index'])->name('ruoli');
        Route::get('/ruoli/nuovo', [RuoliController::class, 'nuovo'])->name('ruoli.nuovo');
        Route::get('/ruoli/{ruolo}', [RuoliController::class, 'modifica'])->name('ruoli.modifica');

        Route::middleware('password.confirm:riautentica')->group(function () {
            Route::post('/utenti/esterni', [UtentiController::class, 'aggiungiEsterno'])->name('utenti.esterno');
            Route::post('/utenti/{utente}/assegnazioni', [UtentiController::class, 'assegna'])->name('utenti.assegna');
            Route::delete('/assegnazioni/{assegnazione}', [UtentiController::class, 'revoca'])->name('assegnazioni.revoca');
            Route::post('/ruoli', [RuoliController::class, 'crea'])->name('ruoli.crea');
            Route::put('/ruoli/{ruolo}', [RuoliController::class, 'salva'])->name('ruoli.salva');
        });

        // Aggiornamento dello schema senza riga di comando (ADR 0001), riservato a chi gestisce gli utenti (#37).
        Route::get('/manutenzione/migrazioni', [MigrazioniController::class, 'pagina'])->name('migrazioni');
        Route::post('/manutenzione/migrazioni', [MigrazioniController::class, 'avvia'])->name('migrazioni.avvia');
    });
});

// Prima installazione: il database è vuoto, quindi niente sessione (la tabella `sessions` non c'è) né CSRF.
Route::post('/manutenzione/migrazioni/prima-installazione', [MigrazioniController::class, 'primaInstallazione'])
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

// Cron HTTP di Aruba (ADR 0001, #46): niente sessione né CSRF, solo il token `CRON_TOKEN`.
Route::get('/manutenzione/cron', CronController::class)
    ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class]);

// Onboarding (#71): il menu Impara e la guida di Merlo, fuori dal gruppo sopra perché lavorano anche sul database vero.
require __DIR__.'/impara.php';
