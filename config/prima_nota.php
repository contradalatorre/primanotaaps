<?php

return [

    /*
    | Indirizzi del Workspace che diventano Amministratore al primo accesso, ma solo finché nell'app nessuno ha
    | mai avuto un ruolo con la gestione utenti (avvio a freddo: su Aruba non c'è una riga di comando per crearlo).
    | In .env: AMMINISTRATORI_INIZIALI=nome.cognome@dominio.it,altro@dominio.it
    */
    'amministratori_iniziali' => array_values(array_filter(array_map(
        fn (string $email) => strtolower(trim($email)),
        explode(',', (string) env('AMMINISTRATORI_INIZIALI', '')),
    ))),

    /*
    | Account di emergenza (#36): password lunga (hash bcrypt) e segreto TOTP (base32), che l'associazione
    | conserva fuori dall'app. Si generano con `php artisan emergenza:credenziali`. Se manca uno dei due,
    | l'accesso di emergenza è spento.
    */
    'emergenza' => [
        'password_hash' => env('EMERGENZA_PASSWORD_HASH'),
        'totp_secret' => env('EMERGENZA_TOTP_SECRET'),
    ],

];
