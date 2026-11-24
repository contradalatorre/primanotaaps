@extends('layouts.app')

@section('titolo', 'Classificazione')

@section('contenuto')
    <h1>Classificazione</h1>
    <p class="nota">Tipologie, attività, iniziative e tag con cui si classificano i movimenti. Un elemento già usato non si cancella: si archivia, e non si sceglie più.</p>

    {{-- ——— Tipologie ——— --}}
    <h2>Tipologie</h2>
    @can('gestire_regole')
        <details class="scheda">
            <summary>Nuova tipologia</summary>
            <form method="POST" action="{{ route('classificazione.crea', 'tipologie') }}" class="modulo modulo-stretto">
                @csrf
                <label>Nome <input type="text" name="nome" maxlength="255" required></label>
                <label>Verso
                    <select name="verso" required>
                        @foreach (\App\Models\Tipologia::VERSI as $valore => $etichetta) <option value="{{ $valore }}">{{ $etichetta }}</option> @endforeach
                    </select>
                    <span class="nota">Non si cambia dopo.</span>
                </label>
                <label>Spiegazione semplice <textarea name="spiegazione" rows="2" maxlength="2000" required></textarea></label>
                @foreach (\App\Models\Tipologia::PROPRIETA as $valore => $etichetta)
                    <label class="spunta"><input type="checkbox" name="proprieta[]" value="{{ $valore }}"> <span>{{ $etichetta }}</span></label>
                @endforeach
                <button type="submit" class="bottone">Aggiungi</button>
            </form>
        </details>
    @endcan
    <ul class="schede">
        @foreach ($tipologie as $tipologia)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $tipologia->nome }}</strong>
                    <span class="etichetta">{{ \App\Models\Tipologia::VERSI[$tipologia->verso] }}</span>
                    @if ($tipologia->archiviata()) <span class="etichetta">archiviata</span> @endif
                    @foreach ($tipologia->proprieta as $proprieta) <span class="etichetta">{{ \App\Models\Tipologia::PROPRIETA[$proprieta] ?? $proprieta }}</span> @endforeach
                </div>
                <p>{{ $tipologia->spiegazione }}@unless ($tipologia->archiviata()) <a href="{{ route('manuale') }}#tipologia-{{ $tipologia->id }}">Nel manuale</a>@endunless</p>
                <p class="nota">
                    Voce Modello D {{ $esercizio }}:
                    {{ ($voci[$tipologia->id] ?? collect())->sortBy('area')->map(fn ($v) => "area $v->area → $v->voce")->join(' · ') ?: 'nessuna: la tipologia si potrà usare quando la tabella delle voci avrà una riga per lei (vedi riclassificazione in blocco)' }}
                </p>
                @can('gestire_regole')
                    <details>
                        <summary class="link">Modifica</summary>
                        <form method="POST" action="{{ route('classificazione.salva', ['tipologie', $tipologia->id]) }}" class="modulo modulo-stretto">
                            @csrf @method('PUT')
                            <label>Nome <input type="text" name="nome" value="{{ $tipologia->nome }}" maxlength="255" required></label>
                            <label>Spiegazione semplice <textarea name="spiegazione" rows="2" maxlength="2000" required>{{ $tipologia->spiegazione }}</textarea></label>
                            @foreach (\App\Models\Tipologia::PROPRIETA as $valore => $etichetta)
                                <label class="spunta"><input type="checkbox" name="proprieta[]" value="{{ $valore }}" @checked(in_array($valore, $tipologia->proprieta, true)) @disabled($tipologia->usata())> <span>{{ $etichetta }}</span></label>
                                @if ($tipologia->usata() && in_array($valore, $tipologia->proprieta, true)) <input type="hidden" name="proprieta[]" value="{{ $valore }}"> @endif
                            @endforeach
                            @if ($tipologia->usata()) <p class="nota">Già usata: le proprietà fiscali non si cambiano da qui.</p> @endif
                            <button type="submit" class="bottone">Salva</button>
                        </form>
                    </details>
                    @include('classificazione.azioni', ['tipo' => 'tipologie', 'elemento' => $tipologia])
                @endcan
            </li>
        @endforeach
    </ul>

    {{-- ——— Attività ——— --}}
    <h2>Attività</h2>
    <p class="nota">Nell’area A le lettere dell’art. 5 del Codice del Terzo Settore previste dallo statuto; nell’area B le attività diverse.</p>
    @can('gestire_regole')
        <details class="scheda">
            <summary>Nuova attività</summary>
            <form method="POST" action="{{ route('classificazione.crea', 'attivita') }}" class="modulo modulo-stretto">
                @csrf
                <label>Area
                    <select name="area" required>
                        @foreach (\App\Area::cases() as $area) <option value="{{ $area->value }}">{{ $area->etichetta() }}</option> @endforeach
                    </select>
                </label>
                <label>Nome <input type="text" name="nome" maxlength="255" required></label>
                <label>Tipo di raccolta fondi (solo area C: da qui la voce è C1 o C2, e dopo la creazione non cambia)
                    <select name="raccolta">
                        <option value="">—</option>
                        @foreach (\App\Models\Attivita::RACCOLTE as $valore => $etichetta) <option value="{{ $valore }}">{{ $etichetta }}</option> @endforeach
                    </select>
                </label>
                <button type="submit" class="bottone">Aggiungi</button>
            </form>
        </details>
    @endcan
    <ul class="schede">
        @foreach ($attivita as $a)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $a->nome }}</strong>
                    <span class="etichetta">Area {{ $a->area->value }}{{ $a->raccolta ? ' · '.strtolower(\App\Models\Attivita::RACCOLTE[$a->raccolta]) : '' }}</span>
                    @if ($a->archiviata()) <span class="etichetta">archiviata</span> @endif
                </div>
                @can('gestire_regole')
                    <details>
                        <summary class="link">Rinomina</summary>
                        <form method="POST" action="{{ route('classificazione.salva', ['attivita', $a->id]) }}" class="modulo-in-riga">
                            @csrf @method('PUT')
                            <label>Nome <input type="text" name="nome" value="{{ $a->nome }}" maxlength="255" required></label>
                            <button type="submit" class="bottone">Salva</button>
                        </form>
                    </details>
                    @include('classificazione.azioni', ['tipo' => 'attivita', 'elemento' => $a])
                @endcan
            </li>
        @endforeach
    </ul>

    {{-- ——— Iniziative ——— --}}
    <h2>Iniziative</h2>
    <form method="GET" action="{{ route('classificazione') }}" class="modulo-in-riga">
        <label>Area e attività predefinite dell’esercizio <input type="number" name="esercizio" value="{{ $esercizio }}" min="2000" max="2100"></label>
        <button type="submit" class="bottone">Mostra</button>
    </form>
    <p class="nota">Un movimento di un’iniziativa prende area e attività da qui; si possono cambiare sul singolo movimento.</p>
    @can('gestire_regole')
        <details class="scheda">
            <summary>Nuova iniziativa</summary>
            <form method="POST" action="{{ route('classificazione.crea', 'iniziative') }}" class="modulo modulo-stretto">
                @csrf
                <input type="hidden" name="esercizio" value="{{ $esercizio }}">
                <label>Nome <input type="text" name="nome" maxlength="255" required></label>
                @include('classificazione.predefiniti', ['predefiniti' => null, 'attivita' => $attivita])
                <button type="submit" class="bottone">Aggiungi</button>
            </form>
        </details>
    @endcan
    <ul class="schede">
        @foreach ($iniziative as $iniziativa)
            @php($predefiniti = $iniziativa->predefinitiPer($esercizio))
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $iniziativa->nome }}</strong>
                    @if ($iniziativa->archiviata()) <span class="etichetta">archiviata</span> @endif
                </div>
                <p class="nota">{{ $esercizio }}: {{ $predefiniti ? 'area '.$predefiniti->area->value.($predefiniti->attivita ? ' · '.$predefiniti->attivita->nome : ' · senza attività') : 'nessuna area predefinita' }}</p>
                @can('gestire_regole')
                    <details>
                        <summary class="link">Modifica</summary>
                        <form method="POST" action="{{ route('classificazione.salva', ['iniziative', $iniziativa->id]) }}" class="modulo modulo-stretto">
                            @csrf @method('PUT')
                            <input type="hidden" name="esercizio" value="{{ $esercizio }}">
                            <label>Nome <input type="text" name="nome" value="{{ $iniziativa->nome }}" maxlength="255" required></label>
                            @include('classificazione.predefiniti', ['predefiniti' => $predefiniti, 'attivita' => $attivita])
                            <button type="submit" class="bottone">Salva</button>
                        </form>
                    </details>
                    @include('classificazione.azioni', ['tipo' => 'iniziative', 'elemento' => $iniziativa])
                @endcan
            </li>
        @endforeach
    </ul>

    {{-- ——— Tag ——— --}}
    <h2>Tag</h2>
    <p class="nota">Un tag raggruppa le imputazioni senza avere mai effetto su Modello D e test.</p>
    @can('gestire_regole')
        <form method="POST" action="{{ route('classificazione.crea', 'tag') }}" class="modulo-in-riga">
            @csrf
            <label>Nuovo tag <input type="text" name="nome" maxlength="255" required></label>
            <button type="submit" class="bottone">Aggiungi</button>
        </form>
    @endcan
    <ul class="schede">
        @foreach ($tag as $t)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $t->nome }}</strong>
                    @if ($t->archiviata()) <span class="etichetta">archiviato</span> @endif
                </div>
                @can('gestire_regole')
                    <details>
                        <summary class="link">Rinomina</summary>
                        <form method="POST" action="{{ route('classificazione.salva', ['tag', $t->id]) }}" class="modulo-in-riga">
                            @csrf @method('PUT')
                            <label>Nome <input type="text" name="nome" value="{{ $t->nome }}" maxlength="255" required></label>
                            <button type="submit" class="bottone">Salva</button>
                        </form>
                    </details>
                    @include('classificazione.azioni', ['tipo' => 'tag', 'elemento' => $t])
                @endcan
            </li>
        @endforeach
    </ul>
@endsection
