{{-- L'esito di un test (glossario: Esito del test) portato da testo e icona, nei colori della contrada: mai il rosso, mai «superato». Vuole $esito. --}}
@php($etichetta = \App\Esercizio\Secondarieta::ETICHETTE[$esito])
<strong class="esito esito-{{ str_replace('_', '-', $esito) }}">
    @if ($esito === 'rispettato')
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/></svg>
    @elseif ($esito === 'vicino')
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
    @else
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/></svg>
    @endif
    <span>{{ $etichetta }}</span>
</strong>
