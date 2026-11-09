@extends('layouts.app')

@section('titolo', 'Impara')

@section('contenuto')
    <h1>Impara</h1>
    <p class="nota">Missioni brevi sulle schermate vere, in uno spazio di prova con dati inventati: non puoi rompere niente. Ogni missione dura pochi minuti e si può saltare, interrompere e riprendere.</p>
    <p><a href="{{ route('impara.timbri') }}" class="link">I tuoi timbri</a>
        @can('gestire_utenti') · <a href="{{ route('impara.avanzamento') }}" class="link">Chi ha completato cosa</a> @endcan</p>

    <div data-merlo="impara-percorsi">
        @include('impara.elenco', ['titolo' => 'Per te', 'nota' => 'Le missioni delle cose che puoi fare con i tuoi permessi.', 'percorsi' => $perTe])
        @include('impara.elenco', ['titolo' => 'Altri percorsi', 'nota' => 'Si fanno solo in prova: lì hai i permessi della missione, così ti alleni prima di avere il ruolo.', 'percorsi' => $altri])
    </div>
@endsection
