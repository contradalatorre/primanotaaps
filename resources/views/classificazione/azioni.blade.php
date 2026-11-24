{{-- Archivia o ripristina; cancella solo se mai usato. Variabili: $tipo (indirizzo), $elemento. --}}
<div class="azioni">
    <form method="POST" action="{{ route($elemento->archiviata() ? 'classificazione.ripristina' : 'classificazione.archivia', [$tipo, $elemento->id]) }}">
        @csrf
        <button type="submit" class="link">{{ $elemento->archiviata() ? 'Ripristina' : 'Archivia' }}</button>
    </form>
    @unless ($elemento->usata())
        <form method="POST" action="{{ route('classificazione.elimina', [$tipo, $elemento->id]) }}" data-conferma="Cancellare «{{ $elemento->nome }}»? Non si può annullare." onsubmit="return confirm(this.dataset.conferma)">
            @csrf @method('DELETE')
            <button type="submit" class="link">Cancella</button>
        </form>
    @endunless
</div>
