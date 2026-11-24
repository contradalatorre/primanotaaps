@extends('layouts.app')

@section('titolo', 'Preferenze')

@section('contenuto')
    <h1>Preferenze</h1>

    <form method="POST" action="{{ route('preferenze.salva') }}" class="modulo">
        @csrf
        @method('PUT')
        <fieldset>
            <legend>Email quando qualcuno ti chiama in una nota</legend>
            @foreach ($opzioni as $valore => $etichetta)
                <label><input type="radio" name="email_menzioni" value="{{ $valore }}" @checked(old('email_menzioni', auth()->user()->email_menzioni) === $valore)> {{ $etichetta }}</label>
            @endforeach
            <span class="nota">L’email dice chi ti ha chiamato, su quale movimento e dove leggere la nota; il testo della nota resta nell’app.</span>
        </fieldset>
        <button type="submit" class="bottone">Salva</button>
    </form>
@endsection
