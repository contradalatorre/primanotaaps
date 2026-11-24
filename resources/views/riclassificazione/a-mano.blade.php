{{-- I movimenti cambiati a mano che una riclassificazione non ha toccato (#62): per import, con il collegamento alla revisione dove la barra dei selezionati li riallinea. Vuole $aMano (lista di `Riclassificazione`). --}}
@php($euro = fn (int $c) => \App\Euro::formatta($c))
<section data-merlo="riclassificazione-a-mano">
    <h2>Cambiati a mano: non si toccano</h2>
    <p class="nota">Hanno un’area o un’attività diversa da quella predefinita dell’iniziativa, scelta sul singolo movimento. Restano come sono. Per allinearli al nuovo predefinito apri la revisione del loro import, seleziona i movimenti e usa «Imposta l’iniziativa» nella barra dei selezionati; i movimenti inseriti a mano si aprono uno per uno.</p>
    @foreach (collect($aMano)->groupBy(fn ($m) => $m['import_id'] ?? '') as $importId => $movimenti)
        <div class="scheda">
            @if ($importId !== '')
                <p><a href="{{ route('import.revisione', [$importId, 'passo' => 'tutti']) }}" class="link">Import n. {{ $importId }}: {{ $movimenti->count() }} {{ $movimenti->count() === 1 ? 'movimento' : 'movimenti' }}</a></p>
            @else
                <p>Movimenti senza import</p>
            @endif
            <ul>
                @foreach ($movimenti as $m)
                    <li>{{ $m['data'] }} · {{ $m['descrizione'] }} · {{ $euro($m['importo']) }} € · <span class="nota">{{ $m['stato'] }}</span>
                        <a href="{{ route('movimenti.classificazione', $m['movimento_id']) }}" class="link">Apri</a></li>
                @endforeach
            </ul>
        </div>
    @endforeach
</section>
