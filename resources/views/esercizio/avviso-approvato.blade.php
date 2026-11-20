{{-- L'avviso di chi modifica un movimento o i dati di un esercizio già approvato (#59, storia 73): si può, ma i prospetti cambiano rispetto a quelli dell'assemblea. Vuole $anno. --}}
@if (\App\Models\DatiEsercizio::per($anno)->approvato())
    <p class="avviso" role="status">L’esercizio {{ $anno }} è già approvato: la modifica è ammessa, ma i prospetti cambiano rispetto alla versione approvata (<a href="{{ route('esercizio.approvazione', $anno) }}" class="link">vedi le differenze</a>).</p>
@endif
