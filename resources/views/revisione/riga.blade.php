@php
    $versione = \App\Classificazione::versione($movimento, true);
    $voci = $movimento->imputazioni->map->etichettaVoce()->filter()->unique()->join(', ');
    $classificazione = $movimento->imputazioni->map(fn ($i) => collect([$i->tipologia?->nome ?? 'tipologia da scegliere', $i->iniziativa?->nome])->filter()->join(' · '))->join('; ');
    $posizione = $ordine->search(fn ($m) => $m->id === $movimento->id);
    $dopo = $ordine->get($posizione + 1);
@endphp
<li class="riga {{ $movimento->confermato() ? 'riga-fatta' : '' }}" id="riga-{{ $movimento->id }}" @if ($dettaglio?->id === $movimento->id) aria-current="true" @endif>
    <span class="riga-sel">
        @unless ($movimento->confermato())
            <input type="checkbox" form="selezione" name="movimenti[{{ $movimento->id }}]" value="{{ $versione }}" aria-label="Seleziona: {{ $movimento->descrizione }}">
        @endunless
    </span>
    <span class="riga-data">{{ $movimento->data->format('d/m') }}</span>
    <span class="riga-desc">
        <a href="{{ $url(['movimento' => $movimento->id]) }}#riga-{{ $movimento->id }}" class="link">{{ $movimento->descrizione }}</a>
        <span class="nota">{{ collect([$movimento->causale, $movimento->fonteProposta()])->filter()->join(' · ') }}</span>
    </span>
    <strong class="riga-importo importo">{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ $euro($movimento->importo) }}</strong>
    <span class="riga-classe">
        {{ $classificazione ?: 'Da classificare' }}
        @if ($voci)<span class="nota">{{ $voci }}</span>@endif
    </span>
    <span class="riga-stato">@include('revisione.pill')</span>
    <span class="riga-azione">@include('revisione.conferma', ['dove' => 'riga', 'dopo' => $dopo, 'etichetta' => ''])</span>
</li>
