{{-- La natura commerciale di un'imputazione (#52): quella che vale, il perché e, se c'è una forzatura, anche il valore calcolato. --}}
@php
    $natura = $imputazione->natura();
    $parola = fn (?bool $c) => $c === null ? 'non calcolabile' : ($c ? 'commerciale' : 'non commerciale');
    $daRivedere = $imputazione->forzaturaDaRivedere();
@endphp
<p class="nota natura" data-merlo="natura-imputazione">
    @if ($imputazione->forzata())
        Natura commerciale: {{ $parola($imputazione->natura_forzata) }} <span class="etichetta">Forzata</span>
        · calcolata: {{ $parola($natura->commerciale) }}
        · forzata da {{ $imputazione->forzataDa?->name }} il {{ $imputazione->forzatura_il?->format('d/m/Y') }}: «{{ $imputazione->forzatura_motivazione }}»
        @if ($daRivedere !== [])
            <br><span class="etichetta" data-merlo="forzatura-da-rivedere">Da rivedere</span> dopo la forzatura {{ count($daRivedere) === 1 ? 'è cambiato questo dato' : 'sono cambiati questi dati' }}: {{ implode(', ', $daRivedere) }}.
        @endif
    @elseif ($natura->commerciale === null)
        Natura commerciale: {{ $natura->regola === 'uscita' ? 'non si applica alle uscite' : 'da calcolare' }} <span class="nota">({{ $natura->perche }})</span>
    @else
        Natura commerciale: {{ $parola($natura->commerciale) }} <span class="nota">({{ $natura->perche }})</span>
    @endif
    @if ($natura->dipendeDal2bis)
        {{-- Il valore calcolato qui è quello con il test rispettato: l'esito vero, con la catena dei test, è nella pagina dei test (#54). --}}
        <a href="{{ route('test-esercizio', $imputazione->movimento->data->year) }}#test-area-a" class="link">Vedi l’esito del test dell’area A</a>
    @endif
</p>
