@extends('layouts.app')

@section('titolo', 'I tuoi timbri')

@section('contenuto')
    <h1>I tuoi timbri</h1>
    <p><a href="{{ route('impara') }}" class="link">Torna a Impara</a></p>

    {{-- Una pergamena sulla torre: un timbro per missione completata, un sigillo per percorso completato. Niente punti né classifiche. --}}
    <div class="pergamena">
        <div class="pergamena-torre" aria-hidden="true">
            <svg viewBox="0 0 240 70" preserveAspectRatio="xMidYMax meet"><path d="M20 70V26h-8V8h20v10h14V8h20v10h14V8h20v10h14V8h20v10h14V8h20v18h-8v44z" fill="#111"/><path d="M104 70V48a16 16 0 0 1 32 0v22z" fill="#ffd900"/></svg>
        </div>
        @if ($nuovo && ($appena = \App\Onboarding\Catalogo::predefinito()->missione($nuovo)))
            <p class="pergamena-nuovo" role="status">Missione «{{ $appena->titolo }}» completata: ecco il tuo timbro!</p>
        @endif
        @foreach ($percorsi as $percorso)
            @continue ($percorso['missioni']->isEmpty())
            <section class="pergamena-percorso">
                <h2>{{ $percorso['titolo'] }}</h2>
                @if ($percorso['sigillo'])
                    <p class="pergamena-sigillo">@include('impara.timbro', ['etichetta' => 'Sigillo '.$percorso['titolo'], 'id' => 'sigillo-'.$percorso['id'], 'sigillo' => true])<span>Percorso completato</span></p>
                @endif
                <ul class="pergamena-timbri">
                    @foreach ($percorso['missioni'] as $missione)
                        <li class="{{ $fatte->has($missione->id) ? 'ottenuto' : 'vuoto-timbro' }} {{ $nuovo === $missione->id ? 'nuovo' : '' }}">
                            @if ($fatte->has($missione->id))
                                @include('impara.timbro', ['etichetta' => $missione->titolo, 'id' => 'timbro-'.$missione->id, 'sigillo' => false])
                                <span>{{ $missione->titolo }}<small>{{ $fatte[$missione->id]->completata_il?->format('d/m/Y') }}</small></span>
                            @else
                                <span class="slot" aria-hidden="true"></span>
                                <span>{{ $missione->titolo }}<small>Non ancora fatta</small></span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
@endsection
