@extends('layouts.app')

@section('titolo', 'Esercizio storico '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Esercizio storico {{ $anno }}</h1>
    <p class="nota">Un esercizio precedente all’avvio dell’app: solo gli importi per voce del Modello D e i saldi finali, senza movimenti. Riempie la colonna dell’esercizio precedente nel <a href="{{ route('modello-d', $anno + 1) }}" class="link">Modello D {{ $anno + 1 }}</a>.</p>

    @if ($storico)
        <p class="avviso" role="status" data-merlo="storico-bloccato">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Caricato il {{ $storico->created_at->format('d/m/Y') }} e bloccato: lo modifica solo un Amministratore, con motivazione, e la modifica va nel registro.
                @can('riaprire_esercizio') <a href="{{ route('esercizio.storico.modifica', $anno) }}" class="link">Modifica</a> @endcan
            </span>
        </p>
        @include('esercizio.storico-totali', ['m' => $m])
        @include('esercizio.storico-differenze', ['differenze' => $storico->differenze])
        <div class="tabella-scorrevole">
            <table class="tabella">
                <tbody>
                    <tr><td>Cassa al 31/12/{{ $anno }}</td><td class="text-right">{{ $euro($storico->saldo_cassa) }}</td></tr>
                    <tr><td>Depositi bancari e postali al 31/12/{{ $anno }}</td><td class="text-right">{{ $euro($storico->saldo_depositi) }}</td></tr>
                    @foreach (['costi' => 'Costi figurativi', 'proventi' => 'Proventi figurativi'] as $tipo => $titolo)
                        <tr><td>{{ $titolo }}</td><td class="text-right">{{ $euro(array_sum($storico->figurativi[$tipo] ?? [])) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @else
        @can('gestire_esercizio')
            <form method="POST" action="{{ route('esercizio.storico.carica', $anno) }}" enctype="multipart/form-data" class="modulo" data-merlo="storico-carica">
                @csrf
                <label>Foglio {{ $anno }} approvato (.xlsx o .ods)
                    <input type="file" name="foglio" accept=".xlsx,.ods" required>
                    <span class="nota">Si legge il foglio «{{ \App\Esercizio\ImportStorico::FOGLIO }}», al massimo 4 MB. Prima di salvare vedi un’anteprima.</span>
                </label>
                @error('foglio') <p class="errore" role="alert">{{ $message }}</p> @enderror
                @error('esercizio') <p class="errore" role="alert">{{ $message }}</p> @enderror
                <button type="submit" class="bottone">Carica e mostra l’anteprima</button>
            </form>
        @else
            <p class="vuoto">L’esercizio storico {{ $anno }} non è stato caricato. Lo carica chi gestisce i dati dell’esercizio.</p>
        @endcan
    @endif
@endsection
