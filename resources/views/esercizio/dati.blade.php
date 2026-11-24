@extends('layouts.app')

@section('titolo', 'Dati dell’esercizio '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Dati dell’esercizio {{ $anno }}</h1>
    @include('esercizio.avviso-approvato', ['anno' => $anno])
    <p class="nota">Le informazioni che i movimenti non dicono. Servono ai test dell’esercizio.
        <a href="{{ route('test-esercizio', $anno) }}" class="link">Test dell’esercizio {{ $anno }}</a> ·
        <a href="{{ route('dati-esercizio', $anno - 1) }}" class="link">{{ $anno - 1 }}</a> ·
        <a href="{{ route('dati-esercizio', $anno + 1) }}" class="link">{{ $anno + 1 }}</a>
    </p>

    <form method="POST" action="{{ route('dati-esercizio.salva', $anno) }}" class="modulo modulo-stretto" data-merlo="dati-esercizio-modulo">
        @csrf
        @method('PUT')
        <label>Dipendenti medi (con i decimali: si arrotonda per eccesso) <input type="number" name="dipendenti_medi" min="0" max="100000" step="any" value="{{ old('dipendenti_medi', $dati->dipendenti_medi) }}"></label>
        <label>Totale dell’attivo (€) <input type="text" name="attivo" inputmode="decimal" maxlength="30" value="{{ old('attivo', $dati->attivo === null ? '' : $euro($dati->attivo)) }}"></label>
        <label>Riparto dei costi promiscui
            <select name="criterio_riparto">
                @foreach (\App\Models\DatiEsercizio::CRITERI_RIPARTO as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('criterio_riparto', $dati->criterio_riparto) === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </label>
        <label class="spunta"><input type="checkbox" name="figurativi_dichiarati" value="1" @checked(old('figurativi_dichiarati', $dati->figurativi_dichiarati))> <span>I costi figurativi qui sotto sono tutti (anche «nessuno»): senza questa spunta il test di secondarietà segnala il dato mancante</span></label>
        <button type="submit" class="bottone">Salva</button>
    </form>

    <h2>Costi e proventi figurativi</h2>
    <p class="nota" data-merlo="figurativi-regola">Solo da ore effettivamente prestate da volontari iscritti nel registro, per la retribuzione oraria del CCNL, per gruppi e senza nomi di persone: mai stime. I proventi sono prestazioni gratuite al loro valore normale, documentato (listini, ricevute). Questa pagina è visibile solo a chi gestisce i dati dell’esercizio; il Modello D e il registro delle modifiche non mostrano le descrizioni.</p>

    @if ($figurativi->isEmpty())
        <p class="vuoto">Nessuna riga figurativa per l’esercizio {{ $anno }}.</p>
    @else
        <ul class="schede" data-merlo="figurativi-elenco">
            @foreach ($figurativi as $riga)
                <li class="scheda">
                    <p><strong>{{ $riga->tipo === 'costo' ? 'Costo' : 'Provento' }} figurativo</strong> · {{ $areeFigurative[$riga->area] ?? 'Area '.$riga->area.' (anomalia)' }}: {{ $euro($riga->importo) }} €</p>
                    <p>{{ $riga->descrizione }}</p>
                    <form method="POST" action="{{ route('dati-esercizio.figurativi.elimina', [$anno, $riga->id]) }}" class="azioni">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bottone-piccolo">Elimina</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('dati-esercizio.figurativi.aggiungi', $anno) }}" class="modulo modulo-stretto" data-merlo="figurativo-modulo">
        @csrf
        <label>Tipo
            <select name="tipo">
                <option value="costo" @selected(old('tipo') === 'costo')>Costo figurativo</option>
                <option value="provento" @selected(old('tipo') === 'provento')>Provento figurativo</option>
            </select>
        </label>
        <label>Riga del prospetto
            <select name="area">
                @foreach ($areeFigurative as $codice => $etichetta)
                    <option value="{{ $codice }}" @selected(old('area') === $codice)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </label>
        <label>Gruppo o prestazione (senza nomi di persone) <input type="text" name="descrizione" maxlength="200" value="{{ old('descrizione') }}" required></label>
        <fieldset>
            <legend>Costo: ore rilevate × retribuzione oraria (l’importo lo calcola l’app)</legend>
            <label>Ore rilevate <input type="text" name="ore" inputmode="decimal" maxlength="12" value="{{ old('ore') }}"></label>
            <label>Retribuzione oraria del CCNL (€/h) <input type="text" name="tariffa" inputmode="decimal" maxlength="12" value="{{ old('tariffa') }}"></label>
        </fieldset>
        <label>Provento: valore normale documentato (€) <input type="text" name="importo" inputmode="decimal" maxlength="30" value="{{ old('importo') }}"></label>
        <button type="submit" class="bottone">Aggiungi la riga</button>
    </form>

    <h2 id="destinazione">Destinazione dell’avanzo</h2>
    <p class="nota" data-merlo="destinazione-regola">L’impiego dell’avanzo che il Tesoriere propone all’assemblea: compare solo nel <a href="{{ route('esercizio.rendiconto', $anno) }}" class="link">rendiconto per l’assemblea</a>, con il residuo; mai nel Modello D. Il rendiconto non porta nomi di persone: non scriverli né nella descrizione né nella nota.</p>

    @if ($destinazioni->isEmpty())
        <p class="vuoto">Nessuna destinazione dell’avanzo per l’esercizio {{ $anno }}.</p>
    @else
        <ul class="schede" data-merlo="destinazione-elenco">
            @foreach ($destinazioni as $riga)
                <li class="scheda">
                    <p><strong>{{ $riga->descrizione }}</strong>: {{ $euro($riga->importo) }} €</p>
                    @if ($riga->nota) <p>{{ $riga->nota }}</p> @endif
                    <form method="POST" action="{{ route('dati-esercizio.destinazione.elimina', [$anno, $riga->id]) }}" class="azioni">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bottone-piccolo">Elimina</button>
                    </form>
                </li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('dati-esercizio.destinazione.aggiungi', $anno) }}" class="modulo modulo-stretto" data-merlo="destinazione-modulo">
        @csrf
        <label>Descrizione (senza nomi di persone) <input type="text" name="descrizione" maxlength="200" value="{{ old('descrizione') }}" required></label>
        <label>Importo (€) <input type="text" name="importo" inputmode="decimal" maxlength="30" value="{{ old('importo') }}" required></label>
        <label>Nota (facoltativa, senza nomi di persone) <input type="text" name="nota" maxlength="200" value="{{ old('nota') }}"></label>
        <button type="submit" class="bottone">Aggiungi la destinazione</button>
    </form>
@endsection
