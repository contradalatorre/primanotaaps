{{-- ✓ di un movimento: conferma lui e porta al successivo (`$dopo`). Senza tipologia resta visibile ma spento: un popover dice perché.
     Chi lo include passa sempre `$etichetta`, anche vuota: quella del gruppo che lo contiene non deve filtrare qui. --}}
@if ($movimento->confermato())
    @include('revisione.icona', ['nome' => 'ok'])
@elseif ($movimento->confermabile())
    <form method="POST" action="{{ route('import.revisione.conferma', $import) }}" class="conferma">
        @csrf
        <input type="hidden" name="passo" value="{{ $passo }}">
        @if ($dopo)<input type="hidden" name="dopo" value="{{ $dopo->id }}">@endif
        <input type="hidden" name="movimenti[{{ $movimento->id }}]" value="{{ \App\Classificazione::versione($movimento, true) }}">
        <button type="submit" class="spunta-conferma" data-conferma aria-label="Conferma: {{ $movimento->descrizione }}">@include('revisione.icona', ['nome' => 'ok'])<span class="testo-conferma">{{ $etichetta ?? '' }}</span></button>
    </form>
@else
    <button type="button" class="spunta-conferma" aria-disabled="true" popovertarget="perche-{{ $movimento->id }}-{{ $dove }}" aria-label="Conferma: {{ $movimento->descrizione }} (manca la tipologia)">@include('revisione.icona', ['nome' => 'ok'])<span class="testo-conferma">{{ $etichetta ?? '' }}</span></button>
    <span popover id="perche-{{ $movimento->id }}-{{ $dove }}" class="popover-conferma">Per confermare serve la tipologia: apri il movimento e scegli la tipologia.</span>
@endif
