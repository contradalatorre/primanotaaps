@extends('layouts.app')

@section('titolo', 'Classifica il movimento')

@section('contenuto')
    <h1>Classifica il movimento</h1>

    <div class="scheda">
        <div class="scheda-testa">
            <strong class="importo">{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} €</strong>
            <span class="etichetta">{{ \App\Models\Movimento::VERSI[$movimento->verso] }}</span>
            <span class="etichetta">{{ ['da_classificare' => 'Da classificare', 'proposto' => 'Proposto', 'confermato' => 'Confermato'][$movimento->stato] }}</span>
        </div>
        <p>{{ $movimento->descrizione }}</p>
        <p class="nota">{{ $movimento->data->format('d/m/Y') }} · {{ $movimento->conto->nome }} · esercizio {{ $movimento->data->year }}</p>
        @if ($movimento->proponente) <p class="nota">Proposta da {{ $movimento->proponente->name }}</p> @endif
        @if ($movimento->confermato()) <p class="nota">Una nuova classificazione lo lascia confermato, a nome di chi la salva.</p> @endif
    </div>

    <form method="POST" action="{{ route('movimenti.classificazione.salva', $movimento) }}" class="modulo modulo-stretto">
        @csrf @method('PUT')

        <fieldset>
            <legend>La controparte è</legend>
            <label class="spunta"><input type="radio" name="tipo_controparte" value="" @checked(old('tipo_controparte', $movimento->tipo_controparte) === null)> <span>Da indicare</span></label>
            @foreach (\App\Models\Movimento::TIPI_CONTROPARTE as $valore => $etichetta)
                <label class="spunta"><input type="radio" name="tipo_controparte" value="{{ $valore }}" @checked(old('tipo_controparte', $movimento->tipo_controparte) === $valore)> <span>{{ $etichetta }}</span></label>
            @endforeach
        </fieldset>

        <p class="nota">Una sola imputazione prende tutto l’importo. Per dividere il movimento scrivi l’importo di ogni imputazione: la somma deve essere {{ \App\Euro::formatta($movimento->importo) }} €. Area e attività si prendono dall’iniziativa, se le lasci come sono.</p>
        @error('imputazioni') <p class="avviso" role="alert">{{ $message }}</p> @enderror

        @for ($i = 0; $i < $righe; $i++)
            @php
                $imputazione = $imputazioni[$i] ?? null;
                // Area e attività uguali a quelle dell'iniziativa restano «ereditate»: se si cambia l'iniziativa le seguono.
                $predefiniti = $imputazione?->iniziativa?->predefinitiPer($movimento->data->year);
                $areaScelta = $predefiniti && $imputazione->area === $predefiniti->area ? null : $imputazione?->area?->value;
                $attivitaScelta = $predefiniti && $imputazione->area === $predefiniti->area && $imputazione->attivita_id === $predefiniti->attivita_id ? null : $imputazione?->attivita_id;
            @endphp
            <fieldset>
                <legend>Imputazione {{ $i + 1 }}</legend>
                <label>Importo (€) <input type="text" name="imputazioni[{{ $i }}][importo]" inputmode="decimal" autocomplete="off" placeholder="{{ \App\Euro::formatta($movimento->importo) }}"
                    value="{{ old("imputazioni.$i.importo", $imputazione && $imputazioni->count() > 1 ? \App\Euro::formatta($imputazione->importo) : '') }}"></label>
                @error("imputazioni.$i.importo") <span class="nota">{{ $message }}</span> @enderror

                <label>Tipologia
                    <select name="imputazioni[{{ $i }}][tipologia_id]">
                        <option value="">Da scegliere</option>
                        @foreach ($tipologie as $t) <option value="{{ $t->id }}" @selected((int) old("imputazioni.$i.tipologia_id", $imputazione?->tipologia_id) === $t->id)>{{ $t->nome }}{{ $t->archiviata() ? ' (archiviata)' : '' }}</option> @endforeach
                    </select>
                </label>
                @error("imputazioni.$i.tipologia_id") <span class="nota">{{ $message }}</span> @enderror

                <label>Iniziativa
                    <select name="imputazioni[{{ $i }}][iniziativa_id]">
                        <option value="">Nessuna</option>
                        @foreach ($iniziative as $ini) <option value="{{ $ini->id }}" @selected((int) old("imputazioni.$i.iniziativa_id", $imputazione?->iniziativa_id) === $ini->id)>{{ $ini->nome }}{{ $ini->archiviata() ? ' (archiviata)' : '' }}</option> @endforeach
                    </select>
                </label>
                @error("imputazioni.$i.iniziativa_id") <span class="nota">{{ $message }}</span> @enderror

                <label>Area
                    <select name="imputazioni[{{ $i }}][area]">
                        <option value="">Come l’iniziativa</option>
                        @foreach (\App\Area::cases() as $area) <option value="{{ $area->value }}" @selected(old("imputazioni.$i.area", $areaScelta) === $area->value)>{{ $area->etichetta() }}</option> @endforeach
                    </select>
                </label>
                @error("imputazioni.$i.area") <span class="nota">{{ $message }}</span> @enderror

                <label>Attività
                    <select name="imputazioni[{{ $i }}][attivita_id]">
                        <option value="">Come l’iniziativa, o nessuna</option>
                        @foreach ($attivita as $a) <option value="{{ $a->id }}" @selected((int) old("imputazioni.$i.attivita_id", $attivitaScelta) === $a->id)>{{ $a->area->value }} · {{ $a->nome }}{{ $a->archiviata() ? ' (archiviata)' : '' }}</option> @endforeach
                    </select>
                </label>
                @error("imputazioni.$i.attivita_id") <span class="nota">{{ $message }}</span> @enderror

                @if ($tag->isNotEmpty())
                    <fieldset>
                        <legend>Tag (non cambiano Modello D né test)</legend>
                        @foreach ($tag as $t)
                            <label class="spunta"><input type="checkbox" name="imputazioni[{{ $i }}][tag][]" value="{{ $t->id }}"
                                @checked(in_array($t->id, (array) old("imputazioni.$i.tag", $imputazione?->tag->pluck('id')->all() ?? []), false))> <span>{{ $t->nome }}</span></label>
                        @endforeach
                    </fieldset>
                @endif

                @if ($imputazione)
                    <p class="nota">Voce Modello D: {{ $imputazione->etichettaVoce() ?? 'da ricavare (manca la tipologia)' }}</p>
                @endif
            </fieldset>
        @endfor

        {{-- «Salva» resta il primo pulsante: è quello che parte con Invio. --}}
        <button type="submit" class="bottone">Salva la classificazione</button>
        @if ($righe < 10) <button type="submit" formaction="{{ route('movimenti.classificazione.riga', $movimento) }}" class="link">Aggiungi un’imputazione</button> @endif
        <a href="{{ route('movimenti', ['conto' => $movimento->conto_id]) }}" class="link">Torna ai movimenti</a>
    </form>

    @can('importare_e_confermare')
        @if ($movimento->stato === 'proposto')
            <form method="POST" action="{{ route('movimenti.conferma', $movimento) }}" class="modulo-in-riga">
                @csrf
                <input type="hidden" name="versione" value="{{ \App\Classificazione::versione($movimento) }}">
                <button type="submit" class="bottone">Conferma il movimento</button>
                <span class="nota">Senza tipologia su ogni imputazione non si conferma.</span>
            </form>
        @endif
    @endcan

    <h2>Giustificativi</h2>
    <ul class="schede">
        @forelse ($movimento->giustificativi as $g)
            <li class="scheda">
                <p>{{ $g->riferimento }} @if ($g->link) · <a href="{{ $g->link }}" class="link" rel="noopener noreferrer" target="_blank">Apri sul Drive</a> @endif</p>
                <form method="POST" action="{{ route('giustificativi.elimina', $g) }}">@csrf @method('DELETE') <button type="submit" class="link">Togli</button></form>
            </li>
        @empty
            <li class="vuoto">Nessun giustificativo.</li>
        @endforelse
    </ul>
    <form method="POST" action="{{ route('movimenti.giustificativi', $movimento) }}" class="modulo modulo-stretto">
        @csrf
        <label>Riferimento <input type="text" name="riferimento" maxlength="255" placeholder="Fattura 12 del 3/5/2026" required></label>
        <label>Link al file sul Drive (facoltativo) <input type="url" name="link" maxlength="2000" placeholder="https://drive.google.com/…"></label>
        <button type="submit" class="bottone">Aggiungi il giustificativo</button>
    </form>

    <details class="scheda">
        <summary>Cosa vuol dire ogni tipologia</summary>
        <dl class="dati">
            @foreach ($tipologie as $t) <div><dt>{{ $t->nome }}</dt><dd>{{ $t->spiegazione }}</dd></div> @endforeach
        </dl>
    </details>
@endsection
