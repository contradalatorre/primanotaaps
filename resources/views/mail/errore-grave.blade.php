Prima nota ha registrato un errore grave.

Dove: {{ $origine }}
Quando: {{ $quando->timezone(config('app.timezone'))->format('d/m/Y H:i') }}
Cosa: {{ $messaggio }}
@if ($volte > 1)
Quante volte: {{ $volte }}
@endif

Lo stato del sistema mostra gli errori aperti e il dettaglio: {{ route('sistema') }}
Se l'errore si ripete, riceverai un'altra email al massimo una volta al giorno.
