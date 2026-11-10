@extends('layouts.app')

@section('titolo', $regola->exists ? 'Modifica regola' : 'Nuova regola')

@php
    $v = fn (string $campo) => old($campo, $valori[$campo] ?? null);
    $tagScelti = array_map('intval', (array) old('tag', $valori['tag'] ?? []));
    $euro = fn (int $c) => \App\Euro::formatta($c);
@endphp

@section('contenuto')
    <h1>{{ $regola->exists ? 'Modifica la regola' : 'Nuova regola' }}</h1>
    <p class="nota">
        Una regola propone la classificazione dei movimenti che soddisfano <strong>tutte</strong> le condizioni indicate; quelle lasciate vuote non contano.
        Vale la prima regola dell’elenco che corrisponde. Si applica solo ai movimenti da classificare o già proposti da una regola, mai ai confermati né alle scelte a mano.
    </p>

    <form method="POST" action="{{ $regola->exists ? route('regole.salva', $regola) : route('regole.crea') }}" class="modulo modulo-stretto">
        @csrf
        @if ($regola->exists) @method('PUT') <input type="hidden" name="regola" value="{{ $regola->id }}"> @endif
        <label>Nome <input type="text" name="nome" value="{{ $v('nome') }}" maxlength="255" required></label>

        <fieldset>
            <legend>Condizioni</legend>
            <label>Conto
                <select name="conto_id">
                    <option value="">Qualunque</option>
                    @foreach ($conti as $conto) <option value="{{ $conto->id }}" @selected((string) $v('conto_id') === (string) $conto->id)>{{ $conto->nome }}</option> @endforeach
                </select>
            </label>
            <label>Verso
                <select name="verso">
                    <option value="">Qualunque</option>
                    @foreach (\App\Models\Movimento::VERSI as $valore => $etichetta) <option value="{{ $valore }}" @selected($v('verso') === $valore)>{{ $etichetta }}</option> @endforeach
                </select>
            </label>
            <label>Causale uguale a <input type="text" name="causale" value="{{ $v('causale') }}" maxlength="255" placeholder="PAGAMENTO TRAMITE POS"></label>
            <label>Descrizione contiene <input type="text" name="testo" value="{{ $v('testo') }}" maxlength="255" placeholder="ENEL"></label>
            <p class="nota">La controparte è nella descrizione dell’estratto. Niente espressioni regolari: si cerca il testo così com’è, senza badare alle maiuscole.</p>
            <label>Importo da (€) <input type="text" name="importo_min" inputmode="decimal" value="{{ $v('importo_min') }}" autocomplete="off" placeholder="10,00"></label>
            <label>Importo fino a (€) <input type="text" name="importo_max" inputmode="decimal" value="{{ $v('importo_max') }}" autocomplete="off" placeholder="20,00"></label>
            <p class="nota">Per un importo esatto scrivi lo stesso valore nei due campi.</p>
            <label>Valida dal <input type="date" name="valida_dal" value="{{ $v('valida_dal') }}"></label>
            <label>Valida fino al <input type="date" name="valida_al" value="{{ $v('valida_al') }}"></label>
        </fieldset>

        <fieldset>
            <legend>Effetti</legend>
            <label>Tipologia
                <select name="tipologia_id">
                    <option value="">Nessuna</option>
                    @foreach ($tipologie as $t) <option value="{{ $t->id }}" @selected((string) $v('tipologia_id') === (string) $t->id)>{{ $t->nome }} ({{ \App\Models\Tipologia::VERSI[$t->verso] }})@if ($t->archiviata()) · archiviata @endif</option> @endforeach
                </select>
            </label>
            <label>Iniziativa
                <select name="iniziativa_id">
                    <option value="">Nessuna</option>
                    @foreach ($iniziative as $i) <option value="{{ $i->id }}" @selected((string) $v('iniziativa_id') === (string) $i->id)>{{ $i->nome }}@if ($i->archiviata()) · archiviata @endif</option> @endforeach
                </select>
            </label>
            <p class="nota">Senza iniziativa scegli l’area, e se vuoi l’attività: l’iniziativa porta con sé la sua area.</p>
            <label>Area
                <select name="area">
                    <option value="">Quella dell’iniziativa</option>
                    @foreach (\App\Area::cases() as $area) <option value="{{ $area->value }}" @selected($v('area') === $area->value)>{{ $area->etichetta() }}</option> @endforeach
                </select>
            </label>
            <label>Attività
                <select name="attivita_id">
                    <option value="">Nessuna</option>
                    @foreach ($attivita as $a) <option value="{{ $a->id }}" @selected((string) $v('attivita_id') === (string) $a->id)>{{ $a->nome }} (area {{ $a->area->value }})@if ($a->archiviata()) · archiviata @endif</option> @endforeach
                </select>
            </label>
            <label>La controparte è
                <select name="tipo_controparte">
                    <option value="">Da indicare</option>
                    @foreach (\App\Models\Regola::TIPI_CONTROPARTE as $valore => $etichetta) <option value="{{ $valore }}" @selected($v('tipo_controparte') === $valore)>{{ $etichetta }}</option> @endforeach
                </select>
            </label>
            @if ($tag->isNotEmpty())
                <fieldset>
                    <legend>Tag</legend>
                    @foreach ($tag as $t)
                        <label class="spunta"><input type="checkbox" name="tag[]" value="{{ $t->id }}" @checked(in_array($t->id, $tagScelti, true))> <span>{{ $t->nome }}</span></label>
                    @endforeach
                </fieldset>
            @endif
            <p class="nota">Le regole non impostano mai la natura commerciale: quella si calcola.</p>
        </fieldset>

        <button type="submit" class="bottone">Salva e applica</button>
        <button type="submit" class="link" formaction="{{ route('regole.anteprima') }}">Vedi l’anteprima</button>
        <a href="{{ route('regole') }}" class="link">Annulla</a>
    </form>

    @if ($anteprima)
        <section class="scheda" aria-labelledby="anteprima">
            <h2 id="anteprima">Anteprima: non è stato salvato nulla</h2>
            <p>Prenderebbe <strong>{{ $anteprima['presi']->count() }}</strong> {{ $anteprima['presi']->count() === 1 ? 'movimento' : 'movimenti' }}.</p>
            @if ($anteprima['presi']->isNotEmpty())
                <ul>
                    @foreach ($anteprima['presi'] as $r)
                        <li>{{ $r['movimento']->data->format('d/m/Y') }} · {{ $r['movimento']->descrizione }} · {{ $r['movimento']->verso === 'uscita' ? '−' : '+' }}{{ $euro($r['movimento']->importo) }} €
                            · {{ $r['da'] ? 'lo toglie alla regola «'.$r['da'].'»' : 'oggi senza regola' }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($anteprima['lasciati']->isNotEmpty())
                <p>Non prenderebbe più <strong>{{ $anteprima['lasciati']->count() }}</strong> {{ $anteprima['lasciati']->count() === 1 ? 'movimento' : 'movimenti' }}, che oggi sono suoi.</p>
                <ul>
                    @foreach ($anteprima['lasciati'] as $r)
                        <li>{{ $r['movimento']->data->format('d/m/Y') }} · {{ $r['movimento']->descrizione }} · {{ $r['a'] ? 'passa alla regola «'.$r['a'].'»' : 'torna da classificare' }}</li>
                    @endforeach
                </ul>
            @endif
            <p class="nota">Solo movimenti da classificare o proposti da una regola: i confermati e le scelte a mano non si toccano.</p>
        </section>
    @endif
@endsection
