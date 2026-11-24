@extends('layouts.app')

@section('titolo', 'Migrazioni')

@section('contenuto')
    <h1>Migrazioni del database</h1>
    <p>Dopo ogni pubblicazione con cambiamenti allo schema, avvia le migrazioni. Si applicano solo quelle ancora da fare.</p>

    <form method="POST" action="{{ route('migrazioni.avvia') }}">
        @csrf
        <button type="submit" class="bottone">Avvia le migrazioni</button>
    </form>

    @if ($esito)
        <h2>Esito</h2>
        <pre>{{ $esito }}</pre>
    @endif
@endsection
