@extends('layouts.app')

@section('titolo', 'Ruoli')

@section('contenuto')
    <h1>Ruoli</h1>
    <p class="nota">Un ruolo è un insieme di permessi scelti da un elenco fisso. I ruoli predefiniti si modificano come gli altri.</p>
    <p><a href="{{ route('ruoli.nuovo') }}" class="bottone">Nuovo ruolo</a></p>

    <ul class="elenco-ruoli">
        @foreach ($ruoli as $ruolo)
            <li>
                <a href="{{ route('ruoli.modifica', $ruolo) }}"><strong>{{ $ruolo->nome }}</strong></a>
                <span class="nota">{{ count($ruolo->permessi) }} permessi · {{ $ruolo->assegnazioni_count }} assegnazioni</span>
            </li>
        @endforeach
    </ul>
@endsection
