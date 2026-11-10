@extends('layouts.app')

@section('titolo', 'Regole')

@section('contenuto')
    <h1>Regole del tesoriere</h1>
    <p class="nota">
        Propongono la classificazione dei movimenti ricorrenti. Vale la <strong>prima regola dell’elenco</strong> che corrisponde: trascina le regole, o usa Su e Giù, per cambiare l’ordine.
        Salvare o riordinare rifà le proposte dei movimenti da classificare e di quelli già proposti da una regola; i confermati e le scelte a mano non cambiano.
    </p>
    <p><a href="{{ route('regole.nuova') }}" class="bottone">Nuova regola</a></p>

    @if ($regole->isEmpty())
        <p>Non ci sono ancora regole.</p>
    @else
        <form method="POST" action="{{ route('regole.ordine') }}" data-regole-ordine>
            @csrf
            <ol class="schede">
                @foreach ($regole as $i => $regola)
                    <li class="scheda" data-regola draggable="true">
                        <input type="hidden" name="ordine[]" value="{{ $regola->id }}">
                        <div class="scheda-testa">
                            <strong>{{ $i + 1 }}. {{ $regola->nome }}</strong>
                            @if ($regola->archiviata()) <span class="etichetta">da correggere: usa un elemento archiviato, non propone</span> @endif
                        </div>
                        <p>Se {{ $regola->condizioniATesto() }}</p>
                        <p class="nota">propone {{ $regola->effettiATesto() }}</p>
                        <div class="azioni">
                            <a href="{{ route('regole.modifica', $regola) }}" class="link">Modifica</a>
                            @unless ($loop->first)
                                <button type="submit" class="link" form="su-{{ $regola->id }}">Su</button>
                            @endunless
                            @unless ($loop->last)
                                <button type="submit" class="link" form="giu-{{ $regola->id }}">Giù</button>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ol>
        </form>
        {{-- Su e Giù mandano l’elenco intero con due regole scambiate: funzionano anche senza trascinare. --}}
        @foreach ($regole as $i => $regola)
            @foreach (['su' => $i - 1, 'giu' => $i + 1] as $verso => $altro)
                @if ($regole->has($altro))
                    @php
                        $ordine = $regole->modelKeys();
                        [$ordine[$i], $ordine[$altro]] = [$ordine[$altro], $ordine[$i]];
                    @endphp
                    <form method="POST" action="{{ route('regole.ordine') }}" id="{{ $verso }}-{{ $regola->id }}">
                        @csrf
                        @foreach ($ordine as $id) <input type="hidden" name="ordine[]" value="{{ $id }}"> @endforeach
                    </form>
                @endif
            @endforeach
        @endforeach
    @endif
@endsection
