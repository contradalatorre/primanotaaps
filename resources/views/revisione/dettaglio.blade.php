@php
    $posizione = $ordine->values()->search(fn ($m) => $m->id === $dettaglio->id);
    $precedente = $posizione !== false && $posizione > 0 ? $ordine->values()[$posizione - 1] : null;
    $successivo = $posizione !== false ? $ordine->values()->get($posizione + 1) : null;
@endphp
<aside class="dettaglio" aria-label="Dettaglio del movimento" id="dettaglio">
    <div class="dettaglio-testa">
        <div>
            <p class="nota">{{ $dettaglio->data->format('d/m/Y') }}@if ($dettaglio->causale) · {{ $dettaglio->causale }}@endif</p>
            <p class="importo dettaglio-importo">{{ $dettaglio->verso === 'uscita' ? '−' : '+' }}{{ $euro($dettaglio->importo) }}</p>
            @include('revisione.pill', ['movimento' => $dettaglio])
        </div>
        <a href="{{ $url() }}#riga-{{ $dettaglio->id }}" class="link" data-chiudi>Chiudi</a>
    </div>
    <p class="dettaglio-originale">{{ $dettaglio->descrizione }}</p>

    <dl class="dati">
        <div><dt>Fonte</dt><dd>@if (($origine = $dettaglio->movimentoDiOrigine()) && $origine->classificabileDa(auth()->user()))<a href="{{ route('movimenti.classificazione', $origine) }}" class="link">{{ $dettaglio->fonteProposta() }}</a>@else{{ $dettaglio->fonteProposta() ?? 'Nessuna proposta: scegli tu la classificazione.' }}@endif</dd></div>
        @forelse ($dettaglio->imputazioni as $imputazione)
            <div><dt>Imputazione</dt><dd>{{ $imputazione->riassunto() }}</dd></div>
            <div><dt>Voce Modello D</dt><dd>{{ $imputazione->etichettaVoce() ?? 'Si ricava da area e tipologia: scegli la tipologia.' }}</dd></div>
        @empty
            <div><dt>Imputazione</dt><dd>Da classificare.</dd></div>
            <div><dt>Voce Modello D</dt><dd>—</dd></div>
        @endforelse
        {{-- La natura commerciale si calcola con l'esercizio (#55 e seguenti): fin lì un segnaposto. --}}
        <div><dt>Natura</dt><dd>Da calcolare: arriva con il calcolo dell’esercizio.</dd></div>
        @if ($dettaglio->giustificativi->isNotEmpty())
            <div><dt>Giustificativi</dt><dd>{{ $dettaglio->giustificativi->pluck('riferimento')->join('; ') }}</dd></div>
        @endif
    </dl>

    <div class="azioni">
        @unless ($dettaglio->confermato())
            <div class="conferma-dettaglio">@include('revisione.conferma', ['movimento' => $dettaglio, 'dove' => 'dettaglio', 'dopo' => $successivo, 'etichetta' => 'Conferma'])</div>
        @endunless
        @include('revisione.dubbio', ['movimento' => $dettaglio])
        <a href="{{ route('movimenti.classificazione', $dettaglio) }}" class="bottone-piccolo">{{ $dettaglio->stato === 'da_classificare' ? 'Classifica' : 'Rivedi la classificazione' }}</a>
        @if ($precedente)<a href="{{ $url(['movimento' => $precedente->id]) }}#riga-{{ $precedente->id }}" class="link" data-precedente>Precedente</a>@endif
        @if ($successivo)<a href="{{ $url(['movimento' => $successivo->id]) }}#riga-{{ $successivo->id }}" class="link" data-successivo>Successivo</a>@endif
    </div>
    @can('note')
        @include('note.elenco', ['movimento' => $dettaglio, 'note' => \App\Models\Nota::di($dettaglio), 'dubbioPreselezionato' => request()->boolean('dubbio')])
    @endcan
    <p class="nota tasti">Tasti: J e K per spostarti, D per segnare o togliere il dubbio, Invio per confermare, Esc per chiudere.</p>
</aside>
