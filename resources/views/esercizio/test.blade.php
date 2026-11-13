@extends('layouts.app')

@section('titolo', 'Test dell’esercizio '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Test dell’esercizio {{ $anno }}</h1>
    <p class="avviso" role="status" data-merlo="test-provvisorio">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
        <span>Provvisorio – aggiornato al {{ $aggiornatoAl->format('d/m/Y H:i') }}. {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }}</span>
    </p>

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

    @include('esercizio.secondarieta')
    @include('esercizio.test-natura')
@endsection
