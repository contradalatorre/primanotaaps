{{-- Le differenze tra i totali del foglio e quelli ricalcolati dall'app: il foglio 2025 aveva una formula sbagliata sull'avanzo da investimenti (#18). --}}
@if ($differenze === [])
    <p class="nota" data-merlo="storico-nessuna-differenza">I totali e gli avanzi scritti nel foglio coincidono con quelli ricalcolati dall’app.</p>
@else
    <p class="avviso" role="status" data-merlo="storico-differenze">
        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
        <span>L’app ricalcola totali e avanzi dagli importi delle voci, e vale il suo calcolo. Differenze dal foglio:</span>
    </p>
    <ul class="elenco">
        @foreach ($differenze as $d)
            <li>{{ $d['etichetta'] }}: nel foglio {{ \App\Euro::formatta($d['foglio']) }} €, ricalcolato {{ \App\Euro::formatta($d['app']) }} €</li>
        @endforeach
    </ul>
@endif
