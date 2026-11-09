@extends('layouts.app')

@section('titolo', 'Missioni completate')

@section('contenuto')
    <h1>Missioni completate</h1>
    <p class="nota">Solo quali missioni ha completato ciascuno: nessun errore, tempo o tentativo.</p>
    <p><a href="{{ route('impara') }}" class="link">Torna a Impara</a></p>

    <ul class="schede">
        @foreach ($utenti as $utente)
            <li class="scheda">
                <div class="scheda-testa"><strong>{{ $utente->name }}</strong></div>
                @php($fatte = $completate->get($utente->id, collect())->pluck('missione'))
                @if ($fatte->isEmpty())
                    <p class="vuoto">Nessuna missione completata.</p>
                @else
                    <ul>@foreach ($missioni->only($fatte->all()) as $missione)<li>{{ $missione->titolo }}</li>@endforeach</ul>
                @endif
            </li>
        @endforeach
    </ul>
@endsection
