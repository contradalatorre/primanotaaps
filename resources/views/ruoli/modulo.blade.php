@extends('layouts.app')

@section('titolo', $ruolo->exists ? 'Modifica ruolo' : 'Nuovo ruolo')

@section('contenuto')
    <h1>{{ $ruolo->exists ? 'Modifica '.$ruolo->nome : 'Nuovo ruolo' }}</h1>

    <form method="POST" action="{{ $ruolo->exists ? route('ruoli.salva', $ruolo) : route('ruoli.crea') }}" class="modulo">
        @csrf
        @if ($ruolo->exists) @method('PUT') @endif
        <label>Nome <input type="text" name="nome" value="{{ old('nome', $ruolo->nome) }}" required maxlength="100"></label>

        <fieldset>
            <legend>Permessi</legend>
            @php($scelti = old('nome') !== null ? old('permessi', []) : $ruolo->permessi)
            @foreach (\App\Permesso::cases() as $permesso)
                <label class="spunta">
                    <input type="checkbox" name="permessi[]" value="{{ $permesso->value }}" @checked(in_array($permesso->value, $scelti, true))>
                    <span>{{ $permesso->etichetta() }} @if ($permesso->haAmbito()) <span class="nota">(limitato ai conti dell’assegnazione)</span> @endif</span>
                </label>
            @endforeach
        </fieldset>

        <button type="submit" class="bottone">Salva</button>
        <a href="{{ route('ruoli') }}" class="link">Annulla</a>
    </form>
@endsection
