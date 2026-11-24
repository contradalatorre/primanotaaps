<?php

/*
| Le impostazioni dei giroconti (#47). Le regole di riconoscimento non stanno qui: le porta il modulo di ogni formato
| (`regoleGiroconti`, registrato in `ImportEstratto::LETTORI`, #33 storia 37), così un formato nuovo si aggiunge in un
| posto solo e un conto chiuso non propone più nulla (le sue regole sono spente).
*/

return [

    // Una metà senza corrispondenza da più di tanti giorni viene segnalata («dopo alcuni giorni», #14).
    'giorni_per_orfana' => 5,

];
