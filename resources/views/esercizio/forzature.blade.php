@extends('layouts.app')

@section('titolo', 'Forzature '.$anno)

@section('contenuto')
    <h1>Forzature della natura commerciale {{ $anno }}</h1>
    <p class="nota">Le imputazioni dell’esercizio con una natura commerciale assegnata a mano. Se dopo la forzatura cambiano i dati da cui dipende il calcolo (area, voce, tipologia, socio o terzo, affiliazione), la forzatura è «da rivedere»: confermala con una nuova motivazione o toglila.</p>
    <form method="POST" action="{{ route('natura.forza') }}" class="modulo modulo-stretto">
        @csrf
        <ul class="schede" data-merlo="forzature-elenco">
            @forelse ($forzate as $i)
                <li class="scheda">
                    <div class="scheda-testa">
                        <strong class="importo">{{ \App\Euro::formatta($i->importo) }} €</strong>
                        <span class="etichetta">{{ $i->etichettaVoce() ?? 'senza voce' }}</span>
                    </div>
                    <p>{{ $i->movimento->descrizione }}</p>
                    <p class="nota">{{ $i->movimento->data->format('d/m/Y') }} · {{ $i->movimento->conto->nome }} · area {{ $i->area->value }} · {{ $i->tipologia?->nome ?? 'senza tipologia' }}</p>
                    @include('esercizio.natura', ['imputazione' => $i])
                    @can('importare_e_confermare')
                        <label class="spunta"><input type="checkbox" name="imputazioni[]" value="{{ $i->id }}"> <span>Scegli</span></label>
                    @endcan
                </li>
            @empty
                <li class="vuoto">Nessuna forzatura in questo esercizio.</li>
            @endforelse
        </ul>
        @if ($forzate->isNotEmpty()) @include('esercizio.forza-modulo') @endif
    </form>
    <p><a href="{{ route('modello-d', $anno) }}" class="link">Torna al Modello D {{ $anno }}</a></p>
@endsection
