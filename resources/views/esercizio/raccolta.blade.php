@extends('layouts.app')

@section('titolo', 'Raccolta '.$anno.' · '.$iniziativa->nome)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    @php($puoScrivere = $modificabile && auth()->user()->can('gestire_esercizio'))
    <h1>Raccolta fondi occasionale · {{ $iniziativa->nome }} · {{ $anno }}</h1>
    @unless ($modificabile)
        <p class="avviso" role="status">L’esercizio {{ $anno }} è approvato: il PDF di deposito stampa la versione approvata e le raccolte non si cambiano più. Per completare la relazione l’Amministratore riapre l’esercizio (<a href="{{ route('esercizio.approvazione', $anno) }}" class="link">vedi l’approvazione</a>).</p>
    @endunless
    <p class="nota">
        <a href="{{ route('esercizio.raccolte', $anno) }}" class="link">Tutte le raccolte {{ $anno }}</a> ·
        Scarica: <a href="{{ route('esporta.raccolta', [$anno, $iniziativa, 'pdf']) }}" class="link">PDF</a> ·
        <a href="{{ route('esporta.raccolta', [$anno, $iniziativa, 'xlsx']) }}" class="link">XLSX</a>
    </p>

    @if (! $quadratura['quadra'])
        <p class="avviso" role="alert">La somma delle raccolte non coincide con la voce C2 del Modello D: <a href="{{ route('esercizio.raccolte', $anno) }}" class="link">vedi la quadratura</a>.</p>
    @endif
    @if ($raccolta['motivazione']['richiesta'] && ! $raccolta['motivazione']['presente'])
        <p class="avviso" role="alert">{{ $raccolta['motivazione']['motivo'] === 'negativo' ? 'Il risultato è negativo' : 'Le spese superano il '.\App\Esercizio\Raccolta::SOGLIA_SPESE_PERCENTO.'% delle entrate' }}: scrivi la motivazione, senza non si approva né si deposita.</p>
    @endif
    @if ($raccolta['senzaRiga']['entrate']['numero'] + $raccolta['senzaRiga']['uscite']['numero'] > 0)
        <p class="avviso" role="status">{{ $raccolta['senzaRiga']['entrate']['numero'] + $raccolta['senzaRiga']['uscite']['numero'] }} imputazioni di questa raccolta non hanno una riga dello schema (la tabella tipologia → riga non conosce la loro tipologia) e non sono nei totali.</p>
    @endif

    @foreach (\App\Esercizio\Tabelle::raccolta($raccolta, $anno) as $sezione)
        <section data-merlo="raccolta-{{ \Illuminate\Support\Str::slug($sezione['titolo']) }}">
            <h2>{{ $sezione['titolo'] }}</h2>
            <div class="tabella-scorrevole">
                <table class="tabella">
                    <thead><tr>@foreach ($sezione['colonne'] as $i => $colonna) <th scope="col" @if ($i > 0 && $sezione['titolo'] !== 'Relazione illustrativa' && $sezione['titolo'] !== 'Evento') class="text-right" @endif>{{ $colonna }}</th> @endforeach</tr></thead>
                    <tbody>
                        @foreach ($sezione['righe'] as $riga)
                            <tr @if ($riga['grassetto']) class="font-bold" @endif>
                                @foreach ($riga['celle'] as $cella)
                                    @if ($cella instanceof \App\Esercizio\Importo) <td class="text-right">{{ $euro($cella->centesimi) }}</td> @else <td>{{ $cella }}</td> @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endforeach

    <section data-merlo="raccolta-guida">
        <h2>Elenco guida per la relazione</h2>
        <p class="nota">L’app ha precompilato date, denaro, valore dei beni, costi e netto. Il resto lo scrive chi ha seguito la raccolta, senza nomi di persone fisiche.</p>
        <ul class="elenco">
            @foreach ($raccolta['relazione']['guida'] as $voce)
                <li>{{ $voce['fatto'] ? '✓' : '☐' }} <strong>{{ $voce['etichetta'] }}</strong> · {{ $voce['suggerimento'] }}</li>
            @endforeach
        </ul>
    </section>

    @if ($puoScrivere)
        <h2>Dati della raccolta</h2>
        <form method="POST" action="{{ route('esercizio.raccolta.salva', [$anno, $iniziativa]) }}" class="modulo modulo-stretto" data-merlo="raccolta-modulo">
            @csrf
            @method('PUT')
            <label>Data di inizio <input type="date" name="dal" value="{{ $valori['dal'] }}"></label>
            <label>Data di fine <input type="date" name="al" value="{{ $valori['al'] }}"></label>
            <label>Ricorrenza o campagna (senza nomi di persone) <input type="text" name="occasione" maxlength="255" value="{{ $valori['occasione'] }}"></label>
            <label>Beni o servizi offerti (senza nomi di persone) <textarea name="beni_servizi" rows="2" maxlength="5000">{{ $valori['beni_servizi'] }}</textarea></label>
            <label>Descrizione (senza nomi di persone) <textarea name="descrizione" rows="3" maxlength="5000">{{ $valori['descrizione'] }}</textarea></label>
            <label>Luogo (senza nomi di persone) <input type="text" name="luogo" maxlength="255" value="{{ $valori['luogo'] }}"></label>
            <label>Modalità (senza nomi di persone) <textarea name="modalita" rows="2" maxlength="5000">{{ $valori['modalita'] }}</textarea></label>
            <label>Finalità (senza nomi di persone) <textarea name="finalita" rows="2" maxlength="5000">{{ $valori['finalita'] }}</textarea></label>
            <label>Dettaglio dei costi (senza nomi di persone) <textarea name="dettaglio_costi" rows="3" maxlength="5000">{{ $valori['dettaglio_costi'] }}</textarea></label>
            <label>Attività di destinazione (art. 5 CTS)
                <select name="attivita_destinazione_id">
                    <option value="">—</option>
                    @foreach ($attivitaDestinazione as $a)
                        <option value="{{ $a->id }}" @selected((string) $valori['attivita_destinazione_id'] === (string) $a->id)>{{ $a->nome }}</option>
                    @endforeach
                </select>
            </label>
            <label>Beni di modico valore ceduti: numero <input type="number" name="beni_modico_numero" min="0" value="{{ $valori['beni_modico_numero'] }}"></label>
            <label>Beni di modico valore ceduti: prezzo di ciascuno (€) <input type="text" name="beni_modico_prezzo" inputmode="decimal" maxlength="30" value="{{ $valori['beni_modico_prezzo'] }}"></label>
            <label>Elargizioni di persone fisiche (€, il solo totale) <input type="text" name="elargizioni_persone_fisiche" inputmode="decimal" maxlength="30" value="{{ $valori['elargizioni_persone_fisiche'] }}"></label>
            <label>Elargizioni di persone giuridiche (€) <input type="text" name="elargizioni_persone_giuridiche" inputmode="decimal" maxlength="30" value="{{ $valori['elargizioni_persone_giuridiche'] }}"></label>
            <label>Motivazione, senza nomi di persone (obbligatoria se il risultato è negativo o le spese superano il {{ \App\Esercizio\Raccolta::SOGLIA_SPESE_PERCENTO }}% delle entrate) <textarea name="motivazione" rows="3" maxlength="5000">{{ $valori['motivazione'] }}</textarea></label>
            <button type="submit" class="bottone">Salva</button>
        </form>
    @endif

    <h2 id="liberalita">Liberalità non monetarie</h2>
    <p class="nota">Beni o servizi donati, inseriti a mano: il valore di mercato stimato secondo il DM 28/11/2019. Entrano solo nel rendiconto di questa raccolta, mai nel Modello D né nei test.</p>
    @forelse ($raccolta['liberalita'] as $l)
        @if ($loop->first) <ul class="schede" data-merlo="liberalita-elenco"> @endif
        <li class="scheda">
            <p><strong>{{ $l['descrizione'] }}</strong> · quantità {{ $l['quantita'] }} · {{ $euro($l['valore']) }} €</p>
            <p class="nota">Criterio di stima: {{ $l['criterio'] }} · documento: {{ $l['documento'] }}</p>
            @if ($puoScrivere)
                <form method="POST" action="{{ route('esercizio.raccolta.liberalita.elimina', [$anno, $iniziativa, $l['id']]) }}" class="azioni">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="bottone-piccolo">Elimina</button>
                </form>
            @endif
        </li>
        @if ($loop->last) </ul> @endif
    @empty
        <p class="vuoto">Nessuna liberalità non monetaria.</p>
    @endforelse
    @if ($puoScrivere)
        <form method="POST" action="{{ route('esercizio.raccolta.liberalita.aggiungi', [$anno, $iniziativa]) }}" class="modulo modulo-stretto" data-merlo="liberalita-modulo">
            @csrf
            <label>Descrizione (senza nomi di persone) <input type="text" name="descrizione" maxlength="255" value="{{ old('descrizione') }}" required></label>
            <label>Quantità <input type="number" name="quantita" min="1" value="{{ old('quantita', 1) }}" required></label>
            <label>Valore stimato del totale (€) <input type="text" name="valore" inputmode="decimal" maxlength="30" value="{{ old('valore') }}" required></label>
            <label>Criterio di stima (senza nomi di persone) <input type="text" name="criterio" maxlength="255" value="{{ old('criterio') }}" required></label>
            <label>Documento (riferimento, senza il nome del donatore) <input type="text" name="documento" maxlength="255" value="{{ old('documento') }}" required></label>
            <button type="submit" class="bottone">Aggiungi la liberalità</button>
        </form>
    @endif
@endsection
