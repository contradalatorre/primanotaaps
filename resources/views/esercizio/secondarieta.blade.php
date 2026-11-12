{{-- Il test di secondarietà (art. 6 CTS, DM 107/2021): esito, margini, criterio proposto e la spiegazione su tre livelli. Vuole $anno, $proposte, $s (Verifiche::secondarieta). --}}
@php
    $euro = fn (int $c) => \App\Euro::formatta($c);
    $pct = fn (?float $p) => $p === null ? 'non calcolabile' : number_format($p, 2, ',', '.').'%';
    $vai = fn (array $chiavi, string $titolo) => route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => implode(',', $chiavi), 'titolo' => $titolo] + ($proposte ? ['proposte' => 1] : []));
    $etichette = \App\Esercizio\Secondarieta::ETICHETTE;
    $punti = fn (?float $p) => $p === null ? 'non calcolabile' : number_format($p, 2, ',', '.').' punti';
    $criteri = $s['criteri'];
    $nomi = ['a' => 'a) entrate B ÷ entrate della gestione', 'b' => 'b) entrate B ÷ (uscite della gestione + costi figurativi)'];
    $di = ['a' => 'delle entrate della gestione', 'b' => 'dei costi complessivi (uscite della gestione e costi figurativi)'];
    $p = $s['proposto'];
    $c = $criteri[$p];
    $spendibile = fn (string $k) => $criteri[$k]['spendibile'] >= 0
        ? 'altre '.$euro($criteri[$k]['spendibile']).' € di entrate B tengono il criterio '.$k
        : 'servono '.$euro(-$criteri[$k]['spendibile']).' € di entrate B in meno per rientrare nel criterio '.$k;
@endphp
<section data-merlo="test-secondarieta">
    <h2>Secondarietà delle attività diverse</h2>
    <p class="nota">Basta uno dei due criteri, esercizio per esercizio. I dati di oggi, senza proiezioni a fine anno.</p>

    <p data-merlo="test-esito">
        @include('esercizio.stato-esito', ['esito' => $s['esito']])
        — con il criterio {{ $p }} ({{ $c['soglia'] }}%):
        le entrate delle attività diverse (area B) sono {{ $euro($c['numeratore']['importo']) }} €, il {{ $pct($c['rapporto']) }} {{ $di[$p] }}.
        @if ($s['esito'] === 'non_rispettato')
            Il limite è {{ $c['soglia'] }}%: è superato di {{ $euro(-$c['margine']) }} € ({{ $punti($c['marginePunti'] === null ? null : -$c['marginePunti']) }}). Per rientrare {{ $spendibile($p) }}.
        @else
            {{ $s['esito'] === 'vicino' ? 'Si è oltre il '.$s['sogliaVicino'].'% del limite.' : '' }}
            Ancora {{ $euro($c['margine']) }} € sotto il limite: {{ $spendibile($p) }}.
        @endif
    </p>

    @if ($s['datoMancante'])
        <p class="avviso" role="status" data-merlo="test-dato-mancante">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Dato mancante: i costi figurativi non sono stati dichiarati completi e il criterio del 66% conta solo quelli inseriti (nessuno, se non ce ne sono). L’esito potrebbe essere migliore.
                @can('gestire_esercizio')
                    <a href="{{ route('dati-esercizio', $anno) }}" class="link">Inserisci i dati dell’esercizio</a>
                @else
                    Chi gestisce i dati dell’esercizio può inserirli.
                @endcan
            </span>
        </p>
    @endif

    <div class="tabella-scorrevole">
        <table class="tabella" data-merlo="test-criteri">
            <caption class="text-left font-bold">I due criteri a confronto</caption>
            <thead><tr><th>Criterio</th><th class="text-right">Rapporto</th><th class="text-right">Limite</th><th>Esito</th><th class="text-right">Margine</th><th>Margine spendibile</th></tr></thead>
            <tbody>
                @foreach ($criteri as $k => $cr)
                    <tr>
                        <td>{{ $nomi[$k] }}@if ($k === $p) <span class="etichetta">proposto</span>@endif @if ($s['confermato'] === $k) <span class="etichetta">confermato</span>@endif</td>
                        <td class="text-right">{{ $pct($cr['rapporto']) }}</td>
                        <td class="text-right">{{ $cr['soglia'] }}%</td>
                        <td>@include('esercizio.stato-esito', ['esito' => $cr['esito']])</td>
                        <td class="text-right">{{ $euro($cr['margine']) }} € ({{ $punti($cr['marginePunti']) }})</td>
                        <td>{{ ucfirst($spendibile($k)) }}.</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div data-merlo="test-criterio">
        <p>Il criterio proposto è il <strong>{{ $p }}</strong> ({{ $c['soglia'] }}%): è quello con più margine.
            @if ($s['confermato'])
                Criterio confermato dal Tesoriere: <strong>{{ $s['confermato'] }}</strong> ({{ $criteri[$s['confermato']]['soglia'] }}%).
            @else
                Non è ancora confermato.
            @endif
        </p>
        @if ($s['confermatoPeggiore'])
            <p class="avviso" role="status">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                <span>Il criterio confermato ({{ $s['confermato'] }}) è «{{ $etichette[$criteri[$s['confermato']]['esito']] }}», mentre il {{ $p }} è «{{ $etichette[$s['esito']] }}»: l’esito qui sopra segue il criterio proposto. Valuta se cambiare la scelta.</span>
            </p>
        @endif
        @can('gestire_esercizio')
            <form method="POST" action="{{ route('test-esercizio.criterio', $anno) }}" class="modulo-in-riga">
                @csrf
                @foreach (\App\Esercizio\Secondarieta::SOGLIE as $k => $soglia)
                    <button type="submit" name="criterio" value="{{ $k }}" class="bottone-piccolo">Conferma il criterio {{ $k }} ({{ $soglia }}%)</button>
                @endforeach
            </form>
        @endcan
    </div>

    <details data-merlo="test-formula">
        <summary>Il calcolo, con i numeri</summary>
        @foreach ($criteri as $k => $cr)
            <p>
                <strong>Criterio {{ $nomi[$k] }}</strong>:
                <a href="{{ $vai($cr['numeratore']['chiavi'], 'Entrate B (attività diverse)') }}" class="link">{{ $euro($cr['numeratore']['importo']) }} €</a>
                ÷ (
                @foreach ($cr['denominatore']['parti'] as $parte)
                    {{ $loop->first ? '' : ($parte['importo'] < 0 ? '−' : '+') }}
                    @if ($parte['chiavi'] !== null)
                        <a href="{{ $vai($parte['chiavi'], $parte['etichetta']) }}" class="link" title="{{ $parte['etichetta'] }}">{{ $euro(abs($parte['importo'])) }} €</a>
                    @else
                        <span title="{{ $parte['etichetta'] }}">{{ $euro(abs($parte['importo'])) }} €</span>
                    @endif
                @endforeach
                ) = {{ $euro($cr['denominatore']['importo']) }} € → {{ $pct($cr['rapporto']) }}, limite {{ $cr['soglia'] }}%.
            </p>
            <ul class="elenco nota">
                @foreach ($cr['denominatore']['parti'] as $parte)
                    <li>{{ $parte['etichetta'] }}@if ($parte['chiavi'] === null) @can('gestire_esercizio') · <a href="{{ route('dati-esercizio', $anno) }}" class="link">dati dell’esercizio</a>@endcan @endif</li>
                @endforeach
            </ul>
        @endforeach
        <p class="nota">Regole fisse: le sponsorizzazioni sono entrate B e contano; le imposte non sono tra i costi; i proventi figurativi non entrano nel 30%; il distacco del personale è fuori dal 30%.</p>
    </details>

    <details data-merlo="test-base-normativa">
        <summary>Base normativa e manuale</summary>
        <ul class="elenco">
            <li>Art. 6 D.Lgs. 117/2017 (Codice del Terzo settore) e art. 13 c. 6: le attività diverse devono essere secondarie e strumentali, e l’organo di amministrazione lo documenta in un’annotazione in calce al rendiconto.</li>
            <li>DM Lavoro 19 maggio 2021 n. 107, art. 3: in ogni esercizio basta che le entrate delle attività diverse non superino il 30% delle entrate complessive oppure il 66% dei costi complessivi; i costi complessivi comprendono i costi figurativi dei volontari iscritti e delle erogazioni gratuite (c. 3); il distacco del personale è escluso (c. 4).</li>
            <li>Se nessun criterio è rispettato valgono le conseguenze dell’art. 4: segnalazione all’ufficio RUNTS entro 30 giorni dall’approvazione e rientro nell’esercizio successivo.</li>
            <li>Manuale: <a href="{{ route('manuale') }}#sezione-19" class="link">§ 19, I test dell’esercizio e le scelte di classificazione</a>; per le sponsorizzazioni <a href="{{ route('manuale') }}#sezione-10" class="link">§ 10</a>.</li>
        </ul>
    </details>
</section>
