@extends('layouts.app')

@section('titolo', 'Anteprima della riclassificazione')

@section('contenuto')
    @php($euro = fn (?int $c) => $c === null ? '—' : \App\Euro::formatta($c))
    @php($etichetteEsiti = \App\Esercizio\Secondarieta::ETICHETTE)
    <h1>Anteprima della riclassificazione</h1>
    <p><strong>{{ $esito['descrizione'] }}</strong></p>
    @if ($cambio['tipo'] === 'iniziativa')
        <p>{{ $esito['aggiornate'] }} {{ $esito['aggiornate'] === 1 ? 'imputazione cambia' : 'imputazioni cambiano' }} area e attività; {{ count($esito['a_mano']) }} {{ count($esito['a_mano']) === 1 ? 'movimento cambiato a mano resta com’è' : 'movimenti cambiati a mano restano come sono' }}.</p>
    @endif
    <p class="nota">Questo è l’effetto: nulla è ancora cambiato. Il confronto usa le sole imputazioni confermate, come i prospetti.</p>

    @foreach ($esito['anni'] as $anno => $e)
        <section>
            <h2>Esercizio {{ $anno }}</h2>
            @if ($e['stato'] === 'approvato') @include('esercizio.avviso-approvato', ['anno' => $anno]) @endif
            @php($d = $e['differenze'])
            @if (! \App\Esercizio\Differenze::presenti($d, ['voci', 'esiti', 'rendiconto']))
                <p class="vuoto">Nessuna differenza nel Modello D, nei test e nel rendiconto per l’assemblea.</p>
            @endif
            @if ($d['voci'] !== [])
                <div class="tabella-scorrevole">
                    <table class="tabella">
                        <thead><tr><th scope="col">Voce del Modello D</th><th scope="col" class="text-right">Prima (€)</th><th scope="col" class="text-right">Dopo (€)</th><th scope="col" class="text-right">Differenza (€)</th></tr></thead>
                        <tbody>
                            @foreach ($d['voci'] as $riga)
                                <tr><td>{{ $riga['etichetta'] }}</td><td class="text-right">{{ $euro($riga['approvato']) }}</td><td class="text-right">{{ $euro($riga['attuale']) }}</td><td class="text-right">{{ $euro(($riga['attuale'] ?? 0) - ($riga['approvato'] ?? 0)) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @if ($d['esiti'] !== [])
                <h3>Test</h3>
                <ul class="elenco">
                    @foreach ($d['esiti'] as $riga)
                        <li>{{ $riga['etichetta'] }}: prima «{{ $etichetteEsiti[$riga['approvato']] ?? $riga['approvato'] ?? '—' }}», dopo «{{ $etichetteEsiti[$riga['attuale']] ?? $riga['attuale'] ?? '—' }}»</li>
                    @endforeach
                </ul>
            @endif
            @if ($d['rendiconto'] !== [])
                <p class="nota">Cambiano anche {{ count($d['rendiconto']) }} {{ count($d['rendiconto']) === 1 ? 'cifra' : 'cifre' }} del rendiconto per l’assemblea.</p>
            @endif
        </section>
    @endforeach
    @if ($esito['anni'] === [])
        <p class="vuoto">Nessun esercizio da confrontare: nessun movimento usa ancora questa tipologia.</p>
    @endif
    @foreach ($esito['depositati'] as $anno)
        <p class="avviso" role="status">L’esercizio {{ $anno }} è depositato: i suoi prospetti restano quelli depositati e non si confrontano.</p>
    @endforeach

    @if ($esito['a_mano'] !== [])
        @include('riclassificazione.a-mano', ['aMano' => $esito['a_mano']])
    @endif
    @if ($esito['da_rivedere'] !== [])
        @include('riclassificazione.da-rivedere', ['daRivedere' => $esito['da_rivedere']])
    @endif

    <form method="POST" action="{{ route('riclassificazione.applica') }}" class="modulo modulo-stretto">
        @csrf
        @foreach ($cambio as $campo => $valore)
            @foreach ((array) $valore as $v)
                @if ($v !== null) <input type="hidden" name="{{ $campo }}{{ is_array($valore) ? '[]' : '' }}" value="{{ $v }}"> @endif
            @endforeach
        @endforeach
        <input type="hidden" name="impronta" value="{{ $esito['impronta'] }}">
        <p class="nota">È un’azione delicata: ti si chiede di rifare l’accesso se l’ultimo è di più di un’ora fa; poi riapri questa anteprima e conferma. Finisce nel registro delle modifiche.</p>
        <button type="submit" class="bottone">Applica la riclassificazione</button>
        <a href="{{ route('riclassificazione', array_filter(['esercizio' => $cambio['esercizio'] ?? null])) }}" class="link">Torna indietro</a>
    </form>
@endsection
