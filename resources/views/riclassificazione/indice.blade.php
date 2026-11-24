@extends('layouts.app')

@section('titolo', 'Riclassificazione in blocco')

@section('contenuto')
    <h1>Riclassificazione in blocco</h1>
    <p class="nota">Cambia in un colpo l’area di un’iniziativa, le proprietà fiscali di una tipologia o una riga della tabella delle voci. Prima di applicare vedi l’effetto su Modello D e test. Gli esercizi depositati non si toccano; uno approvato sì, con l’avviso. Ogni cambiamento va nel registro delle modifiche.</p>

    <form method="GET" action="{{ route('riclassificazione') }}" class="modulo-in-riga">
        <label>Esercizio <input type="number" name="esercizio" value="{{ $esercizio }}" min="2000" max="2100"></label>
        <button type="submit" class="bottone">Mostra</button>
    </form>
    @if ($stato === 'depositato')
        <p class="avviso" role="status">L’esercizio {{ $esercizio }} è depositato: le sue iniziative e la sua tabella delle voci non si cambiano.</p>
    @elseif ($stato === 'approvato')
        <p class="avviso" role="status">L’esercizio {{ $esercizio }} è già approvato: la modifica è ammessa, ma i prospetti cambiano rispetto alla versione approvata.</p>
    @endif

    @if (session('a_mano')) @include('riclassificazione.a-mano', ['aMano' => session('a_mano')]) @endif
    @if (session('da_rivedere')) @include('riclassificazione.da-rivedere', ['daRivedere' => session('da_rivedere')]) @endif

    <h2>Area di un’iniziativa nel {{ $esercizio }}</h2>
    <p class="nota">I movimenti che seguivano l’area predefinita la seguono; quelli con un’altra area o attività, scelte a mano, restano come sono e si elencano. Servono le righe della tabella delle voci per la nuova area.</p>
    <form method="GET" action="{{ route('riclassificazione.anteprima') }}" class="modulo modulo-stretto">
        <input type="hidden" name="tipo" value="iniziativa"><input type="hidden" name="esercizio" value="{{ $esercizio }}">
        <label>Iniziativa
            <select name="iniziativa_id" required>
                @foreach ($iniziative as $i)
                    @php($p = $i->predefinitiPer($esercizio))
                    <option value="{{ $i->id }}">{{ $i->nome }} (oggi area {{ $p->area->value }}{{ $p->attivita ? ' · '.$p->attivita->nome : '' }})</option>
                @endforeach
            </select>
        </label>
        <label>Nuova area
            <select name="area" required>
                @foreach (\App\Area::cases() as $a) <option value="{{ $a->value }}">{{ $a->etichetta() }}</option> @endforeach
            </select>
        </label>
        <label>Nuova attività
            <select name="attivita_id">
                <option value="">Nessuna</option>
                @foreach ($attivita as $a) <option value="{{ $a->id }}">{{ $a->area->value }} · {{ $a->nome }}</option> @endforeach
            </select>
            <span class="nota">Deve stare nella nuova area.</span>
        </label>
        <button type="submit" class="bottone">Vedi l’effetto</button>
    </form>

    <h2>Proprietà fiscali di una tipologia</h2>
    <p class="nota">Valgono per tutti gli esercizi: quelli depositati restano come sono.</p>
    <ul class="schede">
        @foreach ($tipologie as $t)
            <li class="scheda">
                <details>
                    <summary><strong>{{ $t->nome }}</strong> <span class="nota">{{ collect($t->proprieta)->map(fn ($p) => \App\Models\Tipologia::PROPRIETA[$p] ?? $p)->join(', ') ?: 'nessuna proprietà' }}</span></summary>
                    <form method="GET" action="{{ route('riclassificazione.anteprima') }}" class="modulo modulo-stretto">
                        <input type="hidden" name="tipo" value="tipologia"><input type="hidden" name="tipologia_id" value="{{ $t->id }}">
                        @foreach (\App\Models\Tipologia::PROPRIETA as $valore => $etichetta)
                            <label class="spunta"><input type="checkbox" name="proprieta[]" value="{{ $valore }}" @checked(in_array($valore, $t->proprieta, true))> <span>{{ $etichetta }}</span></label>
                        @endforeach
                        <button type="submit" class="bottone">Vedi l’effetto</button>
                    </form>
                </details>
            </li>
        @endforeach
    </ul>

    <h2>Tabella delle voci del Modello D nel {{ $esercizio }}</h2>
    <p class="nota">La voce di un’imputazione si ricava da area e tipologia. Una tipologia senza righe non si può usare; una riga si toglie solo se nessuna imputazione la usa (lascia vuota la voce).</p>
    <form method="GET" action="{{ route('riclassificazione.anteprima') }}" class="modulo modulo-stretto">
        <input type="hidden" name="tipo" value="voce"><input type="hidden" name="esercizio" value="{{ $esercizio }}">
        <label>Tipologia
            <select name="tipologia_id" required>
                @foreach ($tipologie as $t) <option value="{{ $t->id }}">{{ $t->nome }} ({{ \App\Models\Tipologia::VERSI[$t->verso] }})</option> @endforeach
            </select>
        </label>
        <label>Area
            <select name="area" required>
                @foreach (\App\Area::cases() as $a) <option value="{{ $a->value }}">{{ $a->etichetta() }}</option> @endforeach
            </select>
        </label>
        <label>Voce <input type="text" name="voce" maxlength="20" placeholder="A1, E5, Imposte… (vuota: togli la riga)"></label>
        <button type="submit" class="bottone">Vedi l’effetto</button>
    </form>
    <ul class="schede">
        @foreach ($tipologie as $t)
            <li class="scheda">
                <strong>{{ $t->nome }}</strong>
                <span class="nota">{{ ($voci[$t->id] ?? collect())->map(fn ($v) => "area $v->area → $v->voce")->join(' · ') ?: 'nessuna riga: non si può ancora usare' }}</span>
            </li>
        @endforeach
    </ul>
@endsection
