@extends('layouts.app')

@section('titolo', 'Import · '.$import->conto->nome)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c).' €')
    <h1>{{ $import->stato === 'anteprima' ? 'Anteprima dell’import' : 'Import' }} · {{ $import->conto->nome }}</h1>
    <p><a href="{{ route('import.indice', $import->conto) }}" class="link">Tutti gli import del conto</a></p>

    <dl class="dati" data-merlo="anteprima-saldi">
        <div><dt>Stato</dt><dd>{{ \App\Models\ImportEstratto::STATI[$import->stato] }}</dd></div>
        <div><dt>Periodo</dt><dd>dal {{ $import->periodo_dal->format('d/m/Y') }} al {{ $import->periodo_al->format('d/m/Y') }}</dd></div>
        <div><dt>Righe</dt><dd>{{ $import->righe }}</dd></div>
        {{-- Un formato senza saldi (Satispay) non ne ha: niente da confrontare. --}}
        @if ($import->saldo_iniziale !== null)
            <div><dt>Saldo iniziale</dt><dd>{{ $euro($import->saldo_iniziale) }}</dd></div>
            <div><dt>Saldo finale</dt><dd>{{ $euro($import->saldo_finale) }}</dd></div>
        @endif
        @if ($import->stato === 'anteprima')
            @if ($import->saldo_iniziale !== null)
                <div><dt>Saldo dell’app alla stessa data</dt><dd>{{ $euro($saldoApp) }}</dd></div>
            @endif
            <div><dt>Nuovi</dt><dd>{{ count($classi['nuove']) }}</dd></div>
            <div><dt>Già presenti</dt><dd>{{ count($classi['presenti']) }}</dd></div>
            <div><dt>In conflitto</dt><dd>{{ count($classi['conflitti']) }}</dd></div>
            <div><dt>Giroconti proposti</dt><dd>{{ $giroconti }}</dd></div>
        @else
            <div><dt>Movimenti importati</dt><dd>{{ $movimenti }}</dd></div>
            <div><dt>Caricato da</dt><dd>{{ $import->utente->name }}, confermato il {{ $import->confermato_il->format('d/m/Y') }}</dd></div>
        @endif
    </dl>

    @if ($import->stato === 'confermato')
        <p><a href="{{ route('import.revisione', $import) }}" class="bottone" data-merlo="rivedi-movimenti">Rivedi i movimenti</a></p>
    @endif

    @if (! empty($foglio))
        @include('import.foglio')
    @endif

    @if ($import->stato === 'anteprima')
        @if ($buco)
            <p class="avviso" role="status">
                Il saldo iniziale del file ({{ $euro($import->saldo_iniziale) }}) non è quello che l’app ha alla stessa data ({{ $euro($saldoApp) }}):
                mancano dei movimenti, probabilmente dal {{ $buco['da']->format('d/m/Y') }} al {{ $buco['a']->format('d/m/Y') }}.
                Puoi importare lo stesso, ma il conto resta non riconciliato finché quel periodo non è coperto.
            </p>
        @endif
        @if (count($classi['conflitti']))
            <p class="avviso" role="status">Le righe in conflitto non entrano: dopo la conferma le vedi qui e decidi tu.</p>
        @endif
        <form method="POST" action="{{ route('import.conferma', $import) }}" class="modulo-in-riga">
            @csrf
            <button type="submit" class="bottone" data-merlo="conferma-import" @disabled($foglio && ! $foglio['finale']['ok'])>Conferma l’import</button>
        </form>
        <form method="POST" action="{{ route('import.scarta', $import) }}" class="modulo-in-riga">
            @csrf
            <button type="submit" class="link">Scarta l’anteprima</button>
        </form>
    @else
        <h2>Conflitti</h2>
        <p class="nota">Righe con la stessa chiave di un movimento già presente (per la banca data, importo, descrizione e saldo parziale; per PayPal e Satispay il codice della transazione) ma con altri dati diversi, qui sotto. Nulla viene sovrascritto.</p>
        <ul class="schede">
            @forelse ($conflitti as $conflitto)
                @php($riga = $conflitto->rigaEstratto())
                <li class="scheda">
                    <p><strong>{{ $euro($riga->importo) }}</strong> · {{ date('d/m/Y', strtotime($riga->data)) }} · {{ $riga->descrizione }}</p>
                    <ul class="nota">
                        @foreach ($import->conto->differenze($conflitto->movimento, $riga) as $campo => $valori)
                            <li>{{ $campo }}: nel file {{ $valori['file'] }}, già presente {{ $valori['presente'] }}</li>
                        @endforeach
                    </ul>
                    @if ($conflitto->decisione)
                        <p><span class="etichetta">{{ \App\Models\ImportConflitto::DECISIONI[$conflitto->decisione] }}</span></p>
                    @elseif ($import->stato === 'confermato')
                        @if ($conflitto->movimento->trashed())
                            <p class="avviso" role="status">Il movimento già presente è stato annullato con il suo import: si può solo importare questa riga.</p>
                        @endif
                        <form method="POST" action="{{ route('import.decidi', $conflitto) }}" class="modulo-in-riga">
                            @csrf
                            @foreach (\App\Models\ImportConflitto::DECISIONI as $valore => $etichetta)
                                @continue($valore === 'tieni_esistente' && $conflitto->movimento->trashed())
                                <button type="submit" name="decisione" value="{{ $valore }}" class="bottone">{{ $etichetta }}</button>
                            @endforeach
                        </form>
                    @endif
                </li>
            @empty
                <li class="vuoto">Nessun conflitto.</li>
            @endforelse
        </ul>

        @if ($import->stato === 'confermato')
            <h2>Annullare l’import</h2>
            @if ($import->annullabile())
                <form method="POST" action="{{ route('import.annulla', $import) }}" class="modulo-in-riga" onsubmit="return confirm('Annullare l’import per intero? I suoi movimenti escono dall’app.')">
                    @csrf
                    <button type="submit" class="bottone">Annulla l’import</button>
                    <span class="nota">Possibile finché nessun suo movimento è confermato; si annulla per intero e il file si può ricaricare.</span>
                </form>
            @else
                <p class="nota">Almeno un movimento di questo import è confermato: l’import non si annulla più.</p>
            @endif
        @endif
    @endif
@endsection
