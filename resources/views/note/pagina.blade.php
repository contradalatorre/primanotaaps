@extends('layouts.app')

@section('titolo', 'Note del movimento')

@section('contenuto')
    <h1>Note del movimento</h1>
    <p>
        <strong class="importo">{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} €</strong>
        · {{ $movimento->descrizione }} · {{ $movimento->conto->nome }} · {{ $movimento->data->format('d/m/Y') }}
        @if ($movimento->inDubbio()) @include('note.pill-dubbio') @endif
    </p>

    {{-- Un dubbio su un movimento già confermato non è nella revisione: qui chi può confermare lo chiude (#45). --}}
    @if ($movimento->inDubbio())
        @can('importare_e_confermare')
            <form method="POST" action="{{ route('movimenti.dubbio.chiudi', $movimento) }}">@csrf <button type="submit" class="bottone-piccolo">Chiudi il dubbio</button></form>
        @endcan
    @endif

    @include('note.elenco', ['dubbioPreselezionato' => request()->boolean('dubbio')])

    <p><a href="{{ route('movimenti', ['conto' => $movimento->conto_id]) }}" class="link">Torna ai movimenti</a></p>
@endsection
