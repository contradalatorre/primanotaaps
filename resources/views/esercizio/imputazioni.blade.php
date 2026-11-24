@extends('layouts.app')

@section('titolo', 'Imputazioni '.$anno)

@section('contenuto')
    <h1>Imputazioni {{ $anno }}{{ $titolo !== '' ? ' · '.$titolo : '' }}</h1>
    <p class="nota">Le imputazioni {{ $proposte ? 'confermate e proposte' : 'confermate' }} dietro la cifra del Modello D, fuori dai giroconti.</p>
    @if (\App\Models\DatiEsercizio::per($anno)->stato === 'depositato')
        <p class="avviso" role="status" data-merlo="imputazioni-dal-vivo">L’esercizio {{ $anno }} è depositato: questo elenco è letto dai dati di oggi, non è quello depositato (i prospetti del deposito sono nell’istantanea).</p>
    @endif
    <p data-merlo="imputazioni-totali">
        Entrate <strong>{{ \App\Euro::formatta($elenco['entrate']) }} €</strong> · uscite <strong>{{ \App\Euro::formatta($elenco['uscite']) }} €</strong>
        · differenza <strong>{{ \App\Euro::formatta($elenco['entrate'] - $elenco['uscite']) }} €</strong>
    </p>

    <form method="POST" action="{{ route('natura.forza') }}" class="modulo modulo-stretto">
    @csrf
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
                @include('esercizio.natura', ['imputazione' => $i])
                @if ($riga['lato'] === 'entrate') @can('importare_e_confermare')
                    <label class="spunta"><input type="checkbox" name="imputazioni[]" value="{{ $i->id }}"> <span>Scegli per forzare la natura</span></label>
                @endcan @endif
                @if ($movimento->giustificativi->isNotEmpty())
                    <p class="nota">Giustificativi: {{ $movimento->giustificativi->pluck('riferimento')->join('; ') }}</p>
                @endif
                @if ($movimento->classificabileDa(auth()->user()))
                    <p><a href="{{ route('movimenti.classificazione', $movimento) }}" class="link">Apri il movimento</a></p>
                @endif
            </li>
        @empty
            <li class="vuoto">Nessuna imputazione per questa cifra.</li>
        @endforelse
    </ul>
    @if ($elenco['imputazioni']->isNotEmpty()) @include('esercizio.forza-modulo') @endif
    </form>
    <p><a href="{{ route('esercizio.forzature', $anno) }}" class="link">Le forzature della natura commerciale {{ $anno }}</a></p>
    <p><a href="{{ route('modello-d', $anno) }}{{ $proposte ? '?proposte=1' : '' }}" class="link">Torna al Modello D {{ $anno }}</a></p>
@endsection
