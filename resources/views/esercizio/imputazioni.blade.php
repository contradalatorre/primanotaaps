@extends('layouts.app')

@section('titolo', 'Imputazioni '.$anno)

@section('contenuto')
    <h1>Imputazioni {{ $anno }}{{ $titolo !== '' ? ' · '.$titolo : '' }}</h1>
    <p class="nota">Le imputazioni {{ $proposte ? 'confermate e proposte' : 'confermate' }} dietro la cifra del Modello D, fuori dai giroconti.</p>
    <p data-merlo="imputazioni-totali">
        Entrate <strong>{{ \App\Euro::formatta($elenco['entrate']) }} €</strong> · uscite <strong>{{ \App\Euro::formatta($elenco['uscite']) }} €</strong>
        · differenza <strong>{{ \App\Euro::formatta($elenco['entrate'] - $elenco['uscite']) }} €</strong>
    </p>

    <ul class="schede" data-merlo="imputazioni-elenco">
        @forelse ($elenco['imputazioni'] as $riga)
            @php($i = $riga['imputazione'])
            @php($movimento = $i->movimento)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong class="importo">{{ $riga['lato'] === 'uscite' ? '−' : '+' }}{{ \App\Euro::formatta(abs($i->importo)) }} €</strong>
                    <span class="etichetta">{{ $riga['lato'] === 'uscite' ? 'Uscita' : 'Entrata' }}{{ $riga['voce'] ? ' '.$riga['voce'] : '' }}</span>
                    <span class="etichetta">{{ ['da_classificare' => 'Da classificare', 'proposto' => 'Proposto', 'confermato' => 'Confermato'][$movimento->stato] }}</span>
                </div>
                <p>{{ $movimento->descrizione }}</p>
                <p class="nota">
                    {{ $movimento->data->format('d/m/Y') }} · {{ $movimento->conto->nome }} · area {{ $i->area->value }}
                    @if ($i->attivita) · {{ $i->attivita->nome }} @endif
                    @if ($i->iniziativa) · {{ $i->iniziativa->nome }} @endif
                    · {{ $i->tipologia?->nome ?? 'senza tipologia' }}
                    @if ($i->commissione()) · commissione @endif
                </p>
                @if ($movimento->giustificativi->isNotEmpty())
                    <p class="nota">Giustificativi: {{ $movimento->giustificativi->pluck('riferimento')->join('; ') }}</p>
                @endif
                <p><a href="{{ route('movimenti.classificazione', $movimento) }}" class="link">Apri il movimento</a></p>
            </li>
        @empty
            <li class="vuoto">Nessuna imputazione per questa cifra.</li>
        @endforelse
    </ul>
    <p><a href="{{ route('modello-d', $anno) }}{{ $proposte ? '?proposte=1' : '' }}" class="link">Torna al Modello D {{ $anno }}</a></p>
@endsection
