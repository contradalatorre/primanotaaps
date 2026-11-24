@extends('layouts.app')

@section('titolo', 'Conferma l’accesso')

@section('contenuto')
    <h1>Conferma l’accesso</h1>
    <p>Per questa azione serve un accesso recente: l’ultimo risale a più di un’ora fa.</p>

    @if (session('errore'))
        <p class="avviso" role="alert">{{ session('errore') }}</p>
    @endif

    <a href="{{ route('accesso.google', ['riautentica' => 1]) }}" class="bottone">Rifai l’accesso con Google</a>
@endsection
