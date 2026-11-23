{{-- L'avviso di chi modifica un movimento o i dati di un esercizio già approvato (#59, storia 73): si può, ma i prospetti cambiano rispetto a quelli dell'assemblea. Depositato (#60) non si può più. Vuole $anno. --}}
@if (\App\Models\DatiEsercizio::per($anno)->stato === 'depositato')
    <p class="avviso" role="status">L’esercizio {{ $anno }} è depositato: i suoi dati e i suoi movimenti non si cambiano più. Per correggere un errore l’Amministratore lo riapre (<a href="{{ route('esercizio.approvazione', $anno) }}" class="link">vedi il deposito</a>).</p>
@elseif (\App\Models\DatiEsercizio::per($anno)->approvato())
    <p class="avviso" role="status">L’esercizio {{ $anno }} è già approvato: la modifica è ammessa, ma i prospetti cambiano rispetto alla versione approvata (<a href="{{ route('esercizio.approvazione', $anno) }}" class="link">vedi le differenze</a>).</p>
@endif
