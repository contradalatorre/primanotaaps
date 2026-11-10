{{-- Icona e conteggio delle note di un movimento (da `Movimento::conNote`), con il collegamento alla sequenza. `$href` per scegliere dove porta. --}}
<a href="{{ $href ?? route('movimenti.note', $movimento) }}" class="note-icona link" data-note="{{ $movimento->note_count }}" aria-label="Note: {{ $movimento->note_count }}">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M4 5h16v11H9l-5 4z"/></svg>
    <span>{{ $movimento->note_count }}</span>
</a>
