@extends('layouts.app')

@section('titolo', 'Note del movimento')

@section('contenuto')
    <h1>Note del movimento</h1>
    <p>
        <strong class="importo">{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} €</strong>
        · {{ $movimento->descrizione }} · {{ $movimento->conto->nome }} · {{ $movimento->data->format('d/m/Y') }}
        @if ($movimento->inDubbio()) @include('note.pill-dubbio') @endif
    </p>

    @include('note.elenco', ['dubbioPreselezionato' => request()->boolean('dubbio')])

    <p><a href="{{ route('movimenti', ['conto' => $movimento->conto_id]) }}" class="link">Torna ai movimenti</a></p>
@endsection
