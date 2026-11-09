@extends('layouts.app')

@section('titolo', 'Home')

@section('contenuto')
    <h1>Ciao, {{ auth()->user()->name }}</h1>

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
