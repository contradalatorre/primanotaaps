@extends('layouts.app')

@section('titolo', 'Storia del movimento')

@section('contenuto')
    <h1>Storia del movimento</h1>
    <p>
        <strong>{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} €</strong>
        · {{ $movimento->descrizione }} · {{ $movimento->conto->nome }} · {{ $movimento->data->format('d/m/Y') }}
        @if ($movimento->trashed()) <span class="etichetta">eliminato</span> @endif
    </p>
    <p class="nota">Inserito da {{ $movimento->autore->name }} il {{ $movimento->created_at->format('d/m/Y') }}.</p>

    <ul class="schede">
        @foreach ($righe as $riga) @include('registro-riga', ['riga' => $riga]) @endforeach
    </ul>
    <p><a href="{{ route('movimenti', ['conto' => $movimento->conto_id]) }}" class="link">Torna ai movimenti</a></p>
@endsection
