<?php

/*
| Le regole di riconoscimento dei giroconti (#47), per formato di import del conto su cui sta il movimento da
| riconoscere. Ogni formato porta le sue (#33, storia 37): aggiungerne una è una voce qui, ritirarla è toglierla,
| e un conto chiuso non propone più nulla (le sue regole sono spente).
|
| Una regola guarda un movimento del conto (verso; testo di causale e descrizione, senza maiuscole e minuscole) e cerca
| la sua metà su un altro conto del tipo `con`: stesso importo, verso opposto, data entro `giorni` giorni. Con `meta`
| (un testo, dove {conto} è il conto del movimento) l'app propone anche di scrivere la metà che manca sul conto `con`.
*/

return [

    // Una metà senza corrispondenza da più di tanti giorni viene segnalata («dopo alcuni giorni», #14).
    'giorni_per_orfana' => 5,

    'regole' => [
        // shortcut: le parole di causale e descrizione di prelievi e versamenti sono quelle dell'elenco breve di #11, da verificare su estratti veri.
        'credit_agricole_csv' => [
            'prelievo' => ['nome' => 'Prelievo di contanti', 'verso' => 'uscita', 'contiene' => ['PRELIEV'], 'con' => 'cassa', 'giorni' => 3, 'meta' => 'Prelievo da {conto}'],
            'versamento' => ['nome' => 'Versamento di contanti', 'verso' => 'entrata', 'contiene' => ['VERSAMENT'], 'con' => 'cassa', 'giorni' => 3, 'meta' => 'Versamento su {conto}'],
        ],
    ],

];
