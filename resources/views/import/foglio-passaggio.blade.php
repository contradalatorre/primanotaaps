{{-- La data di passaggio dal foglio e la tabella di conversione (#64, #19). --}}
<h2>Data di passaggio</h2>
<p class="nota">
    Finché il foglio è in uso puoi reimportarlo quante volte vuoi. La data di passaggio è l’ultimo giorno che il foglio copre: l’import rifiuta le righe successive,
    così non ci sono mai due fonti per lo stesso periodo. Passata quella data, l’import e la tabella di conversione si chiudono.
    @if ($conto->passaggio_il) Passaggio fissato al <strong>{{ $conto->passaggio_il->format('d/m/Y') }}</strong>{{ $conto->foglioChiuso() ? ': il foglio è chiuso' : '' }}. @endif
</p>
@unless ($conto->foglioChiuso())
    <form method="POST" action="{{ route('import.passaggio', $conto) }}" class="modulo-in-riga">
        @csrf
        <label>Ultimo giorno coperto dal foglio <input type="date" name="passaggio_il" value="{{ old('passaggio_il', $conto->passaggio_il?->toDateString()) }}"></label>
        <button type="submit" class="bottone">Fissa la data</button>
        <span class="nota">Vuota, la toglie. Una data già passata chiude subito il foglio, e non si riapre.</span>
    </form>
@endunless
<p>
    <a href="{{ route('conversione') }}" class="link">Tabella di conversione delle causali</a>
    @if (\App\Conversioni::chiusa()) <span class="nota">(chiusa con l’import)</span> @endif
</p>
