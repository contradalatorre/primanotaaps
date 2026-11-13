{{-- Forfettario dell'art. 86, soglie degli artt. 13, 30 e 31 sull'esercizio precedente e confronto con gli altri esercizi (#55). Vuole $anno, $catena, $soglie, $confronto. --}}
@php
    $euro = fn (int $c) => \App\Euro::formatta($c);
    $pct = fn (?float $p) => $p === null ? 'non calcolabile' : number_format($p, 2, ',', '.').'%';
    $f = $catena['forfettario'];
    $icona = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>';
    $r = $soglie['riferimento'];
    $a13 = $soglie['art13'];
    $stati = ['sotto' => 'sotto il limite', 'vicino' => 'vicino al limite', 'superato' => 'limite superato', 'mancante' => 'dato mancante: conta come superato'];
    $nomi = ['attivo' => 'Totale dell’attivo', 'entrate' => 'Entrate della gestione', 'dipendenti' => 'Dipendenti medi'];
    $valore = fn (string $nome, ?int $v) => $v === null ? '—' : ($nome === 'dipendenti' ? $v : $euro($v).' €');
    $limite = fn (string $nome, int $v) => $nome === 'dipendenti' ? 'più di '.$v : 'più di '.$euro($v).' €';
    $dati = fn () => auth()->user()->can('gestire_esercizio');
    $righeObbligo = ['art30' => 'Art. 30: organo di controllo delle associazioni', 'art31' => 'Art. 31: revisione legale'];
@endphp

<section data-merlo="test-forfettario" id="test-forfettario">
    <h2>Regime forfettario (art. 86)</h2>
    <p class="nota">Ultimo test della catena, dopo l’ente. Un riquadro ridotto: i ricavi commerciali dell’esercizio contro gli 85.000 euro e l’imponibile stimato al 3%, senza calcolo d’imposta. Interessa solo se l’associazione ha esercitato l’opzione nella dichiarazione, che l’app non registra.</p>

    <p data-merlo="test-forfettario-esito">
        @include('esercizio.stato-esito', ['esito' => $f['esito']])
        — ricavi commerciali {{ $euro($f['ricavi']) }} € contro una soglia di {{ $euro($f['soglia']) }} € ({{ $pct($f['rapporto']) }}).
        @if ($f['esito'] === 'non_rispettato')
            La soglia è superata di {{ $euro(-$f['margine']) }} €.
        @else
            Ancora {{ $euro($f['margine']) }} € di margine.
        @endif
        Reddito imponibile stimato al 3%: {{ $euro($f['imponibile']) }} € (una stima, arrotondata per eccesso, non l’imposta).
    </p>

    @if ($f['uscitaDal'] !== null)
        <p class="avviso" role="status" data-merlo="test-forfettario-uscita">
            {!! $icona !!}
            <span>I ricavi superano gli 85.000 euro: dall’esercizio {{ $f['uscitaDal'] }} l’associazione esce dal regime forfettario e torna alle regole ordinarie (art. 86 c. 14 CTS).</span>
        </p>
    @else
        <p class="nota" data-merlo="test-forfettario-uscita">Se in un esercizio i ricavi superano gli 85.000 euro, l’uscita dal regime vale dall’esercizio successivo.</p>
    @endif

    @if ($f['senzaNatura'] > 0)
        <p class="avviso" role="status" data-merlo="test-forfettario-senza-natura">
            {!! $icona !!}
            <span>{{ $f['senzaNatura'] }} {{ $f['senzaNatura'] === 1 ? 'entrata non ha' : 'entrate non hanno' }} una natura calcolabile (manca la tipologia o la voce): per prudenza {{ $f['senzaNatura'] === 1 ? 'conta' : 'contano' }} tra i ricavi.</span>
        </p>
    @endif

    <details data-merlo="test-forfettario-formula">
        <summary>Il calcolo, con i numeri</summary>
        <ul class="elenco">
            <li>Area A, corrispettivi commerciali (per natura o resi tali dal 2-bis): {{ $euro($f['perArea']['A']) }} €</li>
            <li>Area B, attività diverse, sponsorizzazioni comprese: {{ $euro($f['perArea']['B']) }} €, di cui contributi B2 e B4 {{ $euro($f['contributiB']) }} € (contano per prudenza)</li>
            <li>Aree C, D ed E, solo se commerciali per natura o forzate tali: {{ $euro($f['perArea']['C'] + $f['perArea']['D'] + $f['perArea']['E']) }} €</li>
        </ul>
        <p class="nota">Restano fuori quote, liberalità, 5 per mille, corrispettivi dei soci decommercializzati (art. 85), raccolte fondi, interessi e sopravvenienze. Una forzatura prevale. L’accesso al regime si misura sui ricavi dell’esercizio precedente (per il 2026 basta la previsione): questi sono i ricavi che contano dall’anno dopo.</p>
    </details>

    <details data-merlo="test-forfettario-base">
        <summary>Base normativa e manuale</summary>
        <ul class="elenco">
            <li>Art. 86 D.Lgs. 117/2017 (testo dal 13/12/2025, D.Lgs. 186/2025) e Circolare AdE 1/E/2026 § 3.2: ricavi dell’esercizio precedente non superiori a 85.000 euro, imponibile al 3% dei ricavi per le APS, uscita dal periodo successivo al superamento. Contano le attività commerciali abituali, comprese le sponsorizzazioni; restano fuori le attività occasionali e le sopravvenienze.</li>
            <li>Manuale: <a href="{{ route('manuale') }}#sezione-19" class="link">§ 19, I test dell’esercizio e le scelte di classificazione</a>.</li>
        </ul>
    </details>
</section>

<section data-merlo="test-soglie" id="test-soglie">
    <h2>Soglie dimensionali (artt. 13, 30 e 31)</h2>
    <p class="nota">Misurate sull’esercizio precedente, il {{ $r }}: le entrate sono il totale entrate della gestione, senza disinvestimenti e finanziamenti. L’attivo e i dipendenti medi non derivano dai movimenti e si scrivono nei dati dell’esercizio {{ $r }}.</p>

    @if ($a13['modelloE'])
        <p class="avviso" role="status" data-merlo="test-modello-e">
            {!! $icona !!}
            <span>L’esercizio {{ $r }} è sotto i 60.000 euro (entrate {{ $euro($a13['entrate']) }} €): potete redigere il Modello E, la forma aggregata del rendiconto (art. 13 c. 2-bis CTS, dal rendiconto 2026). È una facoltà, e una volta scelta il modello vincola. La scelta e la produzione del Modello E sono a parte.</span>
        </p>
    @endif

    @if ($a13['senzaDati'])
        <p class="avviso" role="status" data-merlo="test-soglie-senza-dati">
            {!! $icona !!}
            <span>L’esercizio {{ $r }} non ha movimenti confermati nell’app, quindi le soglie non si possono misurare e non si dice né che il rendiconto per cassa sia ammesso né che si possa redigere il Modello E.
                @if ($dati())
                    <a href="{{ route('dati-esercizio', $r) }}" class="link">Dati dell’esercizio {{ $r }}</a>
                @endif
            </span>
        </p>
    @endif

    <div class="tabella-scorrevole">
        <table class="tabella" data-merlo="test-soglie-righe">
            <caption class="text-left font-bold">Esercizio {{ $r }}, per l’esercizio {{ $anno }}</caption>
            <thead><tr><th scope="col">Soglia</th><th scope="col">Misura</th><th scope="col">Limite</th><th scope="col">Esito</th></tr></thead>
            <tbody>
                <tr data-merlo="test-soglia-13">
                    <th scope="row">Art. 13: rendiconto per cassa</th>
                    <td>{{ $a13['entrate'] === null ? '—' : $euro($a13['entrate']).' €' }}</td>
                    <td>più di {{ $euro($a13['soglia']) }} € (ente senza personalità giuridica)</td>
                    <td>
                        @if ($a13['esito'] === null) Non calcolabile
                        @else @include('esercizio.stato-esito', ['esito' => $a13['esito']])
                            @if ($a13['esito'] === 'non_rispettato') <span class="nota">soglia superata di {{ $euro(-$a13['margine']) }} €: serve il bilancio per competenza</span>
                            @else <span class="nota">ancora {{ $euro($a13['margine']) }} €</span> @endif
                        @endif
                    </td>
                </tr>
                @foreach ($righeObbligo as $chiave => $titolo)
                    @php
                        $o = $soglie[$chiave];
                    @endphp
                    <tr data-merlo="test-soglia-{{ substr($chiave, 3) }}">
                        <th scope="row">{{ $titolo }}<br><span class="nota">scatta con due limiti su tre superati per due esercizi consecutivi</span></th>
                        <td>
                            @forelse ($o['parametri'] as $nome => $p)
                                {{ $nomi[$nome] }}: {{ $valore($nome, $p['valore']) }} ({{ $stati[$p['stato']] }})<br>
                            @empty — @endforelse
                        </td>
                        <td>
                            @foreach ($o['parametri'] as $nome => $p)
                                {{ $limite($nome, $p['limite']) }}<br>
                            @endforeach
                        </td>
                        <td>
                            @if ($o['esito'] === null) Non calcolabile
                            @else
                                @include('esercizio.stato-esito', ['esito' => $o['esito']])
                                <span class="nota">
                                    {{ $o['superati'] }} {{ $o['superati'] === 1 ? 'limite superato' : 'limiti superati' }} su 3{{ $o['mancanti'] > 0 ? ', di cui '.$o['mancanti'].' per dato mancante' : '' }}.
                                    @if ($o['consecutivi'] === true) Superati anche nell’esercizio prima: l’obbligo c’è.
                                    @elseif ($o['consecutivi'] === null) L’esercizio prima non è nell’app: per prudenza vale come superato.
                                    @elseif ($o['superati'] >= 2) Non superati nell’esercizio prima: l’obbligo nascerebbe se lo fossero anche quest’anno. @endif
                                </span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if (! $a13['senzaDati'] && ($soglie['art30']['mancanti'] > 0 || $soglie['art31']['mancanti'] > 0))
        <p class="avviso" role="status" data-merlo="test-soglie-dato-mancante">
            {!! $icona !!}
            <span>Dato mancante: l’attivo o i dipendenti medi dell’esercizio {{ $r }} non sono stati scritti e per prudenza contano come limite superato. L’esito potrebbe essere migliore.
                @if ($dati())
                    <a href="{{ route('dati-esercizio', $r) }}" class="link">Inserisci i dati dell’esercizio {{ $r }}</a>
                @else
                    Chi gestisce i dati dell’esercizio può inserirli.
                @endif
            </span>
        </p>
    @endif

    <details data-merlo="test-soglie-base">
        <summary>Base normativa e manuale</summary>
        <ul class="elenco">
            <li>Art. 13 c. 2 e 2-bis D.Lgs. 117/2017 (L. 104/2024): il rendiconto per cassa vale per un ente senza personalità giuridica con entrate non superiori a 300.000 euro dell’esercizio precedente, senza disinvestimenti e finanziamenti; la forma aggregata (Modello E, DM 18/2/2026) fino a 60.000 euro, dal rendiconto 2026. L’app non sa se l’ente ha la personalità giuridica.</li>
            <li>Art. 30 c. 2 e art. 31 c. 1: organo di controllo con attivo oltre 150.000 euro, entrate oltre 300.000 euro o più di 7 dipendenti medi; revisione legale con 1.500.000 euro, 3.000.000 euro e 20 dipendenti; in entrambi i casi basta superare due limiti su tre per due esercizi consecutivi. Le entrate degli artt. 30 e 31 sono misurate come quelle dell’art. 13, un’estensione della ricerca.</li>
            <li>Manuale: <a href="{{ route('manuale') }}#sezione-19" class="link">§ 19</a>.</li>
        </ul>
    </details>
</section>

<section data-merlo="test-confronto" id="test-confronto">
    <h2>Confronto con gli altri esercizi</h2>
    <p class="nota">I valori e gli esiti degli ultimi tre esercizi, affiancati; durante l’anno anche lo stesso periodo dell’anno precedente, senza i costi e i proventi figurativi (che sono dell’anno intero). Sono calcolati oggi dai movimenti, non sono le versioni approvate.</p>
    <div class="tabella-scorrevole">
        <table class="tabella" data-merlo="test-confronto-tabella">
            <thead>
                <tr>
                    <th scope="col">Esercizio</th>
                    @foreach ($confronto as $c)
                        <th scope="col" class="text-right">{{ $c['etichetta'] }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @php
                    $riga = [
                        'entrate' => 'Entrate della gestione',
                        'uscite' => 'Uscite della gestione',
                    ];
                    $esiti = [
                        'secondarieta' => 'Secondarietà (art. 6)',
                        'areaA' => 'Area A (art. 79 c. 2 e 2-bis)',
                        'ente' => 'Ente (art. 79 c. 5)',
                        'forfettario' => 'Forfettario (art. 86)',
                    ];
                @endphp
                @foreach ($riga as $chiave => $titolo)
                    <tr data-merlo="test-confronto-{{ $chiave }}">
                        <th scope="row">{{ $titolo }}</th>
                        @foreach ($confronto as $c)
                            <td class="text-right">{{ $c['vuota'] ? '—' : $euro($c[$chiave]).' €' }}</td>
                        @endforeach
                    </tr>
                @endforeach
                @foreach ($esiti as $chiave => $titolo)
                    <tr data-merlo="test-confronto-{{ $chiave }}">
                        <th scope="row">{{ $titolo }}</th>
                        @foreach ($confronto as $c)
                            <td class="text-right">
                                @if ($c['vuota']) —
                                @else
                                    @include('esercizio.stato-esito', ['esito' => $c[$chiave]['esito']])
                                    <br><span class="nota">{{ $chiave === 'forfettario' ? $euro($c[$chiave]['ricavi']).' € di ricavi' : $pct($c[$chiave]['rapporto']) }}</span>
                                @endif
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <p class="nota">Secondarietà: il rapporto del criterio confermato o, senza conferma, di quello proposto. Un esercizio senza movimenti confermati ha «—».</p>
</section>
