@extends('layouts.app')

@section('titolo', 'Home')

@section('contenuto')
    <h1 data-merlo="saluto">Ciao, {{ auth()->user()->name }}</h1>

    {{-- Il test di secondarietà (#53, #17): in home solo se vicino alla soglia o non rispettato; lo stato lo porta testo e icona, mai il rosso. --}}
    @if ($secondarieta)
        @php($criterio = $secondarieta['criteri'][$secondarieta['proposto']])
        <section class="da-vedere" aria-label="Test di secondarietà" data-merlo="home-test-secondarieta">
            <h2>Test di secondarietà</h2>
            <p>@include('esercizio.stato-esito', ['esito' => $secondarieta['esito']])
                — criterio {{ $secondarieta['proposto'] }} ({{ $criterio['soglia'] }}%), con i dati di oggi:
                {{ $secondarieta['esito'] === 'non_rispettato' ? 'soglia superata di '.\App\Euro::formatta(-$criterio['margine']).' €' : 'ancora '.\App\Euro::formatta($criterio['margine']).' € sotto il limite' }}.
                @if ($secondarieta['datoMancante']) Dato mancante: i costi figurativi non sono dichiarati, l’esito potrebbe essere migliore. @endif
            </p>
            <p><a href="{{ route('test-esercizio', today()->year) }}" class="link">Apri il test dell’esercizio</a></p>
        </section>
    @endif

    {{-- Una metà di giroconto senza corrispondenza dopo alcuni giorni si segnala qui (#47): un trasferimento perso emerge. --}}
    @if ($orfane->isNotEmpty())
        <section class="da-vedere" aria-label="Giroconti senza corrispondenza" data-orfane>
            <h2>Giroconti senza corrispondenza <span class="nota">{{ $orfane->count() }}</span></h2>
            <ul class="schede">
                @foreach ($orfane as $proposta)
                    @php($movimento = $proposta['movimento'])
                    <li class="scheda">
                        <p>{{ $movimento->descrizione }}</p>
                        <p class="nota">
                            {{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} € · {{ $movimento->conto->nome }} · del {{ $movimento->data->format('d/m/Y') }}, da {{ $proposta['giorni'] }} giorni senza l’altra metà
                        </p>
                        <div class="azioni">
                            <a href="{{ \App\Giroconti::urlPerAbbinare($movimento) }}" class="bottone-piccolo">Abbinalo</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Le menzioni restano qui finché qualcuno non preme Fatto o risponde nella nota del movimento (#16). --}}
    @if ($daVedere->isNotEmpty())
        <section class="da-vedere" aria-label="Da vedere">
            <h2>Da vedere <span class="nota">{{ $daVedere->count() }}</span></h2>
            <ul class="schede">
                @foreach ($daVedere as $menzione)
                    @php($movimento = $menzione->nota->oggetto)
                    <li class="scheda" id="menzione-{{ $menzione->id }}">
                        <p>
                            <strong>{{ $menzione->nota->autore->name }}</strong> ti ha chiamato
                            @if ($menzione->ruolo) come {{ $menzione->ruolo->nome }} @endif
                            il {{ $menzione->nota->created_at->format('d/m/Y') }}:
                        </p>
                        <p class="testo-nota">{{ $menzione->nota->testo }}</p>
                        <p class="nota">
                            {{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} € · {{ $movimento->descrizione }} · {{ $movimento->conto->nome }}
                        </p>
                        <div class="azioni">
                            <a href="{{ route('movimenti.note', $movimento) }}#nuova-nota" class="bottone-piccolo">Rispondi</a>
                            <form method="POST" action="{{ route('menzioni.fatto', $menzione) }}">
                                @csrf
                                <button type="submit" class="bottone-piccolo">Fatto</button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        </section>
    @else
        <p class="vuoto">Niente da vedere. Qui compariranno i conti e i movimenti dell’associazione.</p>
    @endif
@endsection
