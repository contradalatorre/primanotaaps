{{-- Area e attività predefinite di un'iniziativa. Variabili: $predefiniti (IniziativaEsercizio o null), $attivita (tutte). --}}
<label>Area predefinita
    <select name="area">
        <option value="">Nessuna</option>
        @foreach (\App\Area::cases() as $area) <option value="{{ $area->value }}" @selected($predefiniti?->area === $area)>{{ $area->etichetta() }}</option> @endforeach
    </select>
</label>
<label>Attività predefinita
    <select name="attivita_id">
        <option value="">Nessuna</option>
        @foreach ($attivita->filter(fn ($a) => $a->archiviata_il === null || $a->id === $predefiniti?->attivita_id) as $a) <option value="{{ $a->id }}" @selected($predefiniti?->attivita_id === $a->id)>{{ $a->area->value }} · {{ $a->nome }}</option> @endforeach
    </select>
    <span class="nota">Deve stare nell’area scelta.</span>
</label>
