{{-- Lo stato del prospetto in cima alla pagina: «provvisorio» finché l'esercizio è aperto; dopo l'approvazione la versione e le differenze dalla versione approvata (#59, #18); dopo il deposito l'istantanea, che non cambia più (#60). Vuole $anno, $aggiornatoAl, $proposte, $merlo; facoltativi $nota e $gruppi (le differenze da mostrare: `voci` ed `esiti`, o `rendiconto`). --}}
@php($situazione = \App\Esercizio\Approvazione::situazione($anno))
@php($versione = $situazione['versione'])
<p class="avviso" role="status" data-merlo="{{ $merlo }}">
    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
    @if ($versione?->depositata_il)
        <span>Depositato il {{ $versione->depositata_il->format('d/m/Y') }} · versione {{ $versione->numero }} · approvato il {{ $versione->approvata_il->format('d/m/Y') }}. È l’istantanea del deposito, identica al PDF depositato: non cambia più. {{ $nota ?? '' }}
            <a href="{{ route('esercizio.approvazione', $anno) }}" class="link">Approvazione, deposito e versioni</a></span>
    @elseif ($versione)
        <span>Approvato il {{ $versione->approvata_il->format('d/m/Y') }} · versione {{ $versione->numero }} · aggiornato al {{ $aggiornatoAl->format('d/m/Y H:i') }}. Si può ancora correggere, con avviso: ciò che cambia si vede qui sotto. {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }} {{ $nota ?? '' }}
            <a href="{{ route('esercizio.approvazione', $anno) }}" class="link">Approvazione e versioni</a></span>
    @else
        <span>Provvisorio – aggiornato al {{ $aggiornatoAl->format('d/m/Y H:i') }}. {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }} {{ $nota ?? '' }}
            @if ($situazione['numero'] > 1) Esercizio riaperto: versione {{ $situazione['numero'] }}. @endif
            <a href="{{ route('esercizio.approvazione', $anno) }}" class="link">Approvazione</a></span>
    @endif
</p>
@if ($situazione['differenze'] !== null)
    @include('esercizio.differenze', ['differenze' => $situazione['differenze'], 'gruppi' => $gruppi ?? ['voci', 'esiti']])
@endif
