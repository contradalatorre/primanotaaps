@extends('layouts.app')

@section('titolo', 'Home')

@section('contenuto')
    <h1 data-merlo="saluto">Ciao, {{ auth()->user()->name }}</h1>
    <p class="vuoto">Qui compariranno i conti e i movimenti dell’associazione.</p>
@endsection
