{{-- Le regole che lo storico suggerisce dopo tre conferme coerenti (#43): le condizioni sono già compilate, la regola non si crea da sola. --}}
@if ($suggerimenti->isNotEmpty())
    <section class="scheda" aria-labelledby="suggerimenti">
        <h2 id="suggerimenti">Regole che conviene creare</h2>
        @foreach ($suggerimenti as $s)
            <p>
                Dopo {{ $s['conferme'] }} conferme coerenti: «{{ $s['valori']['testo'] }}» → {{ $s['a_parole'] }}.
                <a href="{{ route('regole.nuova', $s['valori']) }}" class="bottone-piccolo">Crea una regola<span class="sr-only"> per «{{ $s['valori']['testo'] }}»</span></a>
            </p>
        @endforeach
    </section>
@endif
