<?php

return [

    /*
    | Indirizzi del Workspace che diventano Amministratore al primo accesso, ma solo finché nell'app non c'è
    | nessun Amministratore attivo (avvio a freddo: su Aruba non c'è una riga di comando per crearlo).
    | In .env: AMMINISTRATORI_INIZIALI=nome.cognome@dominio.it,altro@dominio.it
    */
    'amministratori_iniziali' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('AMMINISTRATORI_INIZIALI', '')),
    ))),

];
