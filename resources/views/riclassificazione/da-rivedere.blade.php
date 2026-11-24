{{-- Le regole e le righe di conversione che portano ancora l'area vecchia (#62): non si cambiano da sole. Vuole $daRivedere (lista di `Riclassificazione`). --}}
<section data-merlo="riclassificazione-da-rivedere">
    <h2>Da rivedere: portano ancora l’area vecchia</h2>
    <p class="nota">Continuano a proporre l’area di prima. La riclassificazione non le cambia: se serve, le correggi tu.</p>
    <ul>
        @foreach ($daRivedere as $voce)
            <li>{{ $voce['cosa'] }} @can('gestire_regole') <a href="{{ $voce['url'] }}" class="link">Apri</a> @endcan</li>
        @endforeach
    </ul>
</section>
