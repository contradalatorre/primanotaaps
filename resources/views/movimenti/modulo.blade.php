@extends('layouts.app')

@section('titolo', $movimento->exists ? 'Modifica movimento' : 'Nuovo movimento')

@section('contenuto')
    <h1>{{ $movimento->exists ? 'Modifica il movimento' : 'Nuovo movimento' }} · {{ $conto->nome }}</h1>

    <form method="POST" action="{{ $movimento->exists ? route('movimenti.salva', $movimento) : route('movimenti.crea', $conto) }}" class="modulo modulo-stretto">
        @csrf
        @if ($movimento->exists) @method('PUT') @endif
        <fieldset>
            <legend>Verso</legend>
            @foreach (\App\Models\Movimento::VERSI as $valore => $etichetta)
                <label class="spunta"><input type="radio" name="verso" value="{{ $valore }}" @checked(old('verso', $movimento->verso) === $valore) required> <span>{{ $etichetta }}{{ $valore === 'entrata' ? ' (soldi che entrano in cassa)' : ' (soldi che escono dalla cassa)' }}</span></label>
            @endforeach
        </fieldset>
        <label>Importo (€) <input type="text" name="importo" inputmode="decimal" autocomplete="off" placeholder="12,50" value="{{ old('importo', $movimento->exists ? \App\Euro::formatta($movimento->importo) : '') }}" required></label>
        <label>Data <input type="date" name="data" value="{{ old('data', $movimento->data?->toDateString()) }}" min="{{ $conto->aperto_il->toDateString() }}" max="{{ ($conto->chiuso_il ?? today())->toDateString() }}" required></label>
        <label>Descrizione <input type="text" name="descrizione" value="{{ old('descrizione', $movimento->descrizione) }}" maxlength="1000" required></label>
        <label>Valuta originale (facoltativa) <input type="text" name="valuta_originale" value="{{ old('valuta_originale', $movimento->valuta_originale) }}" maxlength="100" placeholder="es. 20 USD"></label>
        <p class="nota">L’importo è sempre in euro; se la spesa era in un’altra valuta, annotala qui.</p>

        <button type="submit" class="bottone">Salva</button>
        <a href="{{ route('movimenti', ['conto' => $conto->id]) }}" class="link">Annulla</a>
    </form>
@endsection
