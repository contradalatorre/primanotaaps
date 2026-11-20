@extends('layouts.app')

@section('titolo', 'Test dell’esercizio '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Test dell’esercizio {{ $anno }}</h1>
    @include('esercizio.stato-prospetto', ['merlo' => 'test-provvisorio'])

    <form method="GET" action="{{ route('test-esercizio', $anno) }}" class="modulo-in-riga">
        <label class="spunta"><input type="checkbox" name="proposte" value="1" @checked($proposte) onchange="this.form.submit()"> <span>Includi anche le imputazioni proposte (la scelta non si salva)</span></label>
        <noscript><button type="submit" class="bottone">Aggiorna</button></noscript>
    </form>
    <p class="nota" data-merlo="test-in-attesa">
        In attesa di conferma: {{ $inAttesa['entrate']['numero'] }} {{ $inAttesa['entrate']['numero'] === 1 ? 'entrata' : 'entrate' }} per {{ $euro($inAttesa['entrate']['importo']) }} €
        e {{ $inAttesa['uscite']['numero'] }} {{ $inAttesa['uscite']['numero'] === 1 ? 'uscita' : 'uscite' }} per {{ $euro($inAttesa['uscite']['importo']) }} €.
        <a href="{{ route('movimenti') }}" class="link">Movimenti</a>
    </p>
    <p class="nota">
        <a href="{{ route('test-esercizio', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('test-esercizio', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a> ·
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a>
        @can('gestire_esercizio') · <a href="{{ route('dati-esercizio', $anno) }}" class="link">Dati dell’esercizio</a> @endcan
    </p>

    @include('esercizio.forzature-link', ['anno' => $anno])

    @include('esercizio.secondarieta')
    @include('esercizio.test-natura')
    @include('esercizio.test-soglie')
@endsection
