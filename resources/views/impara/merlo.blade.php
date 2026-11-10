{{-- Tutto ciò che Merlo mette in una pagina: in prova la cornice, il nastro e il dialogo; al primo accesso l'invito. Un solo @include nel layout. --}}
@php
    $guida = request()->attributes->get('guida');
@endphp
@if ($guida)
    @php
        $passo = $guida['passo'];
        $ultimo = $guida['numero'] === $guida['totale'];
        $posa = $guida['qui'] ? $passo->posa : 'pensa';
        $frase = $guida['qui'] ? $passo->frase : 'Torniamo lì? Il passo che ci aspetta è in un’altra schermata.';
    @endphp
    <div class="prova-cornice" aria-hidden="true"></div>
    <div class="prova-barra" role="region" aria-label="Spazio di prova">
        <span class="prova-etichetta">
            <svg width="16" height="16" viewBox="0 0 16 16" aria-hidden="true"><path d="M2 14 14 2M2 9l7-7M7 14l7-7" stroke="#ffd900" stroke-width="2"/></svg>
            <span><b>Stai facendo pratica</b>: nessun dato vero</span>
        </span>
        <span class="prova-passo" aria-hidden="true">{{ $guida['missione']->titolo }} · passo {{ $guida['numero'] }} di {{ $guida['totale'] }}</span>
        <form method="POST" action="{{ route('prova.esci') }}">
            @csrf
            <button type="submit" class="prova-esci">Esci dalla prova</button>
        </form>
    </div>

    <section class="merlo-guida {{ $guida['rapida'] ? 'rapida' : '' }}" id="merlo-guida" aria-label="Guida di Merlo"
        data-guida data-elemento="{{ $guida['qui'] ? $passo->elemento : '' }}" data-pratico="{{ $passo->pratico() ? 1 : 0 }}" data-rapida="{{ $guida['rapida'] ? 1 : 0 }}">
        <div class="merlo-ritratto">@include('impara.merlo-svg', ['pose' => $posa])</div>
        <div class="merlo-corpo">
            <p class="merlo-chi">Merlo · passo {{ $guida['numero'] }} di {{ $guida['totale'] }}</p>
            {{-- Senza script il testo c'è tutto; lo script lo fa scrivere da solo (e con movimento ridotto lo lascia intero). --}}
            <p class="merlo-frase"><span class="sr-only">{{ $frase }}</span><span aria-hidden="true" data-frase data-testo="{{ $frase }}">{{ $frase }}</span></p>
            @unless ($guida['missione']->telefono)
                <p class="merlo-computer" data-computer>Questa missione va meglio dal computer, con lo schermo largo. Puoi continuare qui, ma le tabelle sono strette.
                    <button type="button" class="merlo-link" data-continua-qui>Continua qui</button></p>
            @endunless
            <ol class="merlo-pallini" aria-label="Passo {{ $guida['numero'] }} di {{ $guida['totale'] }}">
                @for ($i = 1; $i <= $guida['totale']; $i++)<li class="{{ $i < $guida['numero'] ? 'fatto' : ($i === $guida['numero'] ? 'ora' : '') }}"></li>@endfor
            </ol>
            <div class="merlo-azioni">
                <form method="POST" action="{{ route('prova.esci') }}">@csrf<button type="submit" class="merlo-link">Interrompi</button></form>
                <form method="POST" action="{{ route('prova.salta') }}">@csrf<button type="submit" class="merlo-link">Salta la missione</button></form>
                @if (! $guida['qui'])
                    @if ($guida['url'])<a href="{{ $guida['url'] }}" class="merlo-bottone">Sì, andiamo</a>@endif
                @elseif ($passo->pratico())
                    <span class="merlo-tocca">Tocca a te</span>
                @else
                    <form method="POST" action="{{ route('prova.avanti') }}" class="merlo-avanti">@csrf<button type="submit" class="merlo-bottone" data-avanti>{{ $ultimo ? 'Finito' : 'Avanti' }}</button></form>
                @endif
            </div>
        </div>
    </section>
@elseif (request()->routeIs('home') && app(\App\Onboarding\Svolgimento::class)->invitoDovuto(auth()->user()))
    <div data-invito>
        <section class="merlo-invito" role="region" aria-labelledby="invito-titolo">
            <div class="merlo-invito-uccello">@include('impara.merlo-svg', ['pose' => 'saluta'])</div>
            <h2 id="invito-titolo">Ciao, sono Merlo!</h2>
            <p>Ti faccio vedere come si usa l’app? Sono 5 minuti, su dati inventati: non puoi rompere niente.</p>
            <div class="merlo-invito-azioni">
                @php
                    $prima = app(\App\Onboarding\Svolgimento::class)->suggerite(auth()->user())->first();
                @endphp
                <form method="POST" action="{{ route('impara.avvia', $prima->id) }}">@csrf<button type="submit" class="merlo-bottone" data-primo>Iniziamo</button></form>
                <form method="POST" action="{{ route('impara.invito') }}">@csrf<button type="submit" class="merlo-link">Non ora</button></form>
            </div>
        </section>
    </div>
@endif
