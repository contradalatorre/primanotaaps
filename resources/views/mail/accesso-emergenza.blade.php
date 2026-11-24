Qualcuno è entrato in Prima nota con l’account di emergenza.

Quando: {{ $quando->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
Da: {{ $ip ?? 'indirizzo sconosciuto' }}

L’account di emergenza serve solo quando Google non funziona o non resta nessun Amministratore. Se non sei stato tu o non ti risulta, cambia subito la password e il segreto del TOTP e controlla il registro degli accessi.
