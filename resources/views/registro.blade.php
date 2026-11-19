@extends('layouts.app')

@section('titolo', 'Registro delle modifiche')

@section('contenuto')
    <h1>Registro delle modifiche</h1>
    <p class="nota">Chi ha cambiato cosa e quando, con i valori prima e dopo. Si aggiungono righe, non si cambiano né si tolgono.</p>

    <form method="GET" action="{{ route('registro') }}" class="modulo-in-riga">
        <label>Oggetto
            <select name="oggetto">
                <option value="">Tutti</option>
                <option value="movimento" @selected($oggetto === 'movimento')>Movimenti</option>
                <option value="conto" @selected($oggetto === 'conto')>Conti</option>
                @foreach (['tipologia' => 'Tipologie', 'attivita' => 'Attività', 'iniziativa' => 'Iniziative', 'tag' => 'Tag', 'import' => 'Import', 'manuale' => 'Manuale', 'nota' => 'Note', 'regola' => 'Regole', 'impostazioni' => 'Impostazioni', 'esercizio' => 'Esercizi', 'dati_esercizio' => 'Dati degli esercizi', 'riga_figurativa' => 'Righe figurative', 'destinazione_avanzo' => 'Destinazioni dell’avanzo'] as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected($oggetto === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </label>
        <label>Persona
            <select name="utente">
                <option value="">Tutte</option>
                @foreach ($utenti as $u) <option value="{{ $u->id }}" @selected($utenteId === $u->id)>{{ $u->name }}</option> @endforeach
            </select>
        </label>
        <button type="submit" class="bottone">Filtra</button>
    </form>

    <ul class="schede">
        @forelse ($righe as $riga) @include('registro-riga', ['riga' => $riga]) @empty <li class="vuoto">Nessuna modifica registrata.</li> @endforelse
    </ul>
    {{ $righe->links() }}
@endsection
