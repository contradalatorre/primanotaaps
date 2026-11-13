{{-- La catena dei test sulla natura commerciale (#54): 2-bis sull'area A, poi la natura calcolata, poi il test dell'ente; il pareggio per iniziativa e l'annotazione in calce. Vuole $anno, $proposte, $s, $catena, $annotazione. --}}
@php
    $euro = fn (int $c) => \App\Euro::formatta($c);
    $pct = fn (?float $p) => $p === null ? 'non calcolabile' : number_format($p, 2, ',', '.').'%';
    $vai = fn (array $chiavi, string $titolo) => route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => implode(',', $chiavi), 'titolo' => $titolo] + ($proposte ? ['proposte' => 1] : []));
    $a = $catena['areaA'];
    $e = $catena['ente'];
    $t = $a['tolleranza'];
    $icona = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>';
@endphp

<section data-merlo="test-area-a" id="test-area-a">
    <h2>Natura non commerciale dell’area A (art. 79 c. 2 e 2-bis)</h2>
    <p class="nota">Primo test della catena: se i corrispettivi dell’area A superano i costi effettivi, diventano commerciali, e il test dell’ente li conta di conseguenza. Tutte le attività di interesse generale si valutano insieme (enti sotto i 300.000 euro, Circolare 1/E/2026).</p>

    <p data-merlo="test-area-a-esito">
        @include('esercizio.stato-esito', ['esito' => $a['esito']])
        <span class="etichetta">{{ \App\Esercizio\TestNatura::STATI[$a['stato']] }}</span>
        — Corrispettivi dell’area A: {{ $euro($a['corrispettivi']['importo']) }} €, costi effettivi {{ $euro($a['costi']['importo']) }} €: il {{ $pct($a['rapporto']) }}.
        @if ($a['stato'] === 'pareggio')
            I corrispettivi non superano i costi: ancora {{ $euro(-$a['scarto']) }} € di margine.
        @elseif ($a['stato'] === 'tolleranza')
            I corrispettivi superano i costi di {{ $euro($a['scarto']) }} €, entro la tolleranza del 6% (fino a {{ $euro($a['limiteConTolleranza']) }} €): l’area A resta non commerciale, ma questo esercizio consuma uno dei tre della tolleranza.
        @else
            I corrispettivi superano i costi di {{ $euro($a['scarto']) }} €{{ $t['disponibile'] ? ', oltre la tolleranza del 6% (fino a '.$euro($a['limiteConTolleranza']).' €)' : '' }}: il test non è rispettato.
        @endif
    </p>

    @if ($a['stato'] === 'non_rispettato')
        <p class="avviso" role="status" data-merlo="test-area-a-avviso">
            {!! $icona !!}
            @if ($a['reseCommerciali']['numero'] > 0)
                <span>Il test dell’area A ha reso commerciali {{ $a['reseCommerciali']['numero'] }} {{ $a['reseCommerciali']['numero'] === 1 ? 'imputazione' : 'imputazioni' }} per {{ $euro($a['reseCommerciali']['importo']) }} €: i corrispettivi dei terzi in area A, nel test dell’ente, contano tra i proventi commerciali. Le forzature prevalgono.</span>
            @else
                <span>Nessuna imputazione è stata resa commerciale: i corrispettivi dei terzi in area A sono forzati o non ci sono.</span>
            @endif
        </p>
    @endif

    <p data-merlo="test-tolleranza"><strong>Tolleranza del 6%.</strong> {{ $t['motivo'] }}</p>

    <details data-merlo="test-area-a-formula">
        <summary>Il calcolo, con i numeri</summary>
        <p>
            Corrispettivi: <a href="{{ $vai($a['corrispettivi']['chiavi'], 'Corrispettivi dell’area A (A2, A3, A7, A9)') }}" class="link">{{ $euro($a['corrispettivi']['importo']) }} €</a>
            (voci A2, A3, A7 e A9, dei soci e dei terzi) contro costi effettivi {{ $euro($a['costi']['importo']) }} €.
        </p>
        <ul class="elenco nota">
            <li>Uscite dell’area A (costi diretti): <a href="{{ $vai($a['costi']['diretti']['chiavi'], 'Uscite dell’area A') }}" class="link">{{ $euro($a['costi']['diretti']['importo']) }} €</a></li>
            @foreach ($a['costi']['generali']['parti'] as $parte)
                <li>{{ $parte['etichetta'] }}: <a href="{{ $vai($parte['chiavi'], $parte['etichetta']) }}" class="link">{{ $euro($parte['importo']) }} €</a></li>
            @endforeach
            @php($q = $a['costi']['quota'])
            <li>Spese generali {{ $euro($a['costi']['generali']['importo']) }} € per la quota dell’area A: {{ $euro($q['numeratore']) }} € ÷ {{ $euro($q['denominatore']) }} € ({{ $q['criterio'] === 'costi_diretti' ? 'costi diretti delle aree A–D' : 'ricavi delle aree A–D' }}, dai dati dell’esercizio) = {{ $euro($q['importo']) }} €, arrotondati per difetto.</li>
            <li>I costi figurativi non si contano. Sono soci e terzi insieme perché i costi si ripartiscono pro-rata sui proventi: il confronto dei soli terzi dà lo stesso esito.</li>
        </ul>
    </details>

    <details data-merlo="test-area-a-base">
        <summary>Base normativa e manuale</summary>
        <ul class="elenco">
            <li>Art. 79 c. 2 e 2-bis D.Lgs. 117/2017: un’attività di interesse generale è non commerciale se i corrispettivi non superano i costi effettivi, compresi quelli generali, finanziari e tributari; è tollerato uno scarto fino al 6%, per al massimo tre periodi d’imposta consecutivi dal 2026. Un avanzo oltre il limite rende l’attività commerciale in quell’esercizio.</li>
            <li>Circolare AdE 1/E/2026 §§ 2.2.1 e 2.2.2: i costi figurativi non si contano; il triennio riparte dall’esercizio successivo a uno chiuso a pareggio, e dopo un esercizio commerciale serve il pareggio.</li>
            <li>Manuale: <a href="{{ route('manuale') }}#sezione-19" class="link">§ 19, I test dell’esercizio e le scelte di classificazione</a>.</li>
        </ul>
    </details>
</section>

<section data-merlo="test-ente" id="test-ente">
    <h2>Natura non commerciale dell’ente (art. 79 c. 5)</h2>
    <p class="nota">Ultimo test della catena, dopo l’esito dell’area A: l’ente è commerciale se i proventi commerciali superano le entrate non commerciali. Contano le aree A, B e C.</p>

    <p data-merlo="test-ente-esito">
        @include('esercizio.stato-esito', ['esito' => $e['esito']])
        — proventi commerciali {{ $euro($e['commerciali']) }} € contro entrate non commerciali {{ $euro($e['nonCommerciali']) }} € ({{ $pct($e['rapporto']) }}).
        @if ($e['esito'] === 'non_rispettato')
            I proventi commerciali prevalgono di {{ $euro(-$e['margine']) }} €.
        @else
            Ancora {{ $euro($e['margine']) }} € di margine.
        @endif
    </p>

    @if ($e['effettoDal'] !== null)
        <p class="avviso" role="status" data-merlo="test-ente-effetto">
            {!! $icona !!}
            <span>{{ $e['effettoDal'] > $anno ? 'Per il 2026 e il 2027 il cambio di qualifica vale dal periodo successivo' : 'Il cambio di qualifica vale dall’inizio del periodo d’imposta' }} ({{ $e['effettoDal'] }}; art. 79 c. 5-ter CTS).</span>
        </p>
    @endif

    @if ($e['datoMancante'])
        <p class="avviso" role="status" data-merlo="test-ente-dato-mancante">
            {!! $icona !!}
            <span>Dato mancante: i dati dell’esercizio non sono dichiarati completi e il valore normale delle prestazioni gratuite conta solo se inserito. L’esito potrebbe essere migliore.
                @can('gestire_esercizio')
                    <a href="{{ route('dati-esercizio', $anno) }}" class="link">Inserisci i dati dell’esercizio</a>
                @else
                    Chi gestisce i dati dell’esercizio può inserirlo.
                @endcan
            </span>
        </p>
    @endif

    @if ($e['senzaNatura'] > 0)
        <p class="avviso" role="status" data-merlo="test-ente-senza-natura">
            {!! $icona !!}
            <span>{{ $e['senzaNatura'] }} {{ $e['senzaNatura'] === 1 ? 'entrata non ha' : 'entrate non hanno' }} una natura calcolabile (manca la tipologia o la voce): per prudenza {{ $e['senzaNatura'] === 1 ? 'conta' : 'contano' }} tra i proventi commerciali.</span>
        </p>
    @endif

    <details data-merlo="test-ente-formula" open>
        <summary>Il calcolo, con i numeri</summary>
        <ul class="elenco">
            <li>Proventi commerciali {{ $euro($e['commerciali']) }} €: B1, B3, B5 e B6 abituali, i contributi B2 e B4, i corrispettivi dell’area A diventati commerciali e quelli commerciali per natura.</li>
            <li>Entrate non commerciali {{ $euro($e['nonCommerciali']) }} €, di cui valore normale delle prestazioni gratuite {{ $euro($e['valoreNormale']) }} €: quote, liberalità, 5 per mille, contributi di A, raccolte occasionali, corrispettivi dell’area A finché il 2-bis regge e i corrispettivi dei soci decommercializzati (art. 85).</li>
            <li>Sponsorizzazioni, pubblicità e raccolte fondi abituali, neutre {{ $euro($e['neutre']) }} €: non pesano su nessun piatto.</li>
            @if ($e['fuoriDalTest'] > 0)
                <li>Entrate di attività finanziarie e di supporto generale (aree D ed E) {{ $euro($e['fuoriDalTest']) }} €: fuori dal test.</li>
            @endif
        </ul>
        <p class="nota">Il valore normale è quello dei proventi figurativi dell’area A nei dati dell’esercizio (documentato con listini e ricevute) e conta solo se l’area A è non commerciale. I contributi in B2 e B4 sono commerciali; una forzatura motivata li sposta.</p>
    </details>

    <details data-merlo="test-ente-base">
        <summary>Base normativa e manuale</summary>
        <ul class="elenco">
            <li>Art. 79 c. 5, 5-bis e 5-ter D.Lgs. 117/2017 e Circolare AdE 1/E/2026 §§ 2.3.1–2.3.4: i proventi commerciali si confrontano con le entrate non commerciali; le sponsorizzazioni sono neutre; il contributo destinato a un’attività commerciale è commerciale (§ 2.3.3.1); il valore normale delle prestazioni gratuite delle attività non commerciali è tra le entrate non commerciali (§ 2.3.3.5).</li>
            <li>Manuale: <a href="{{ route('manuale') }}#sezione-19" class="link">§ 19</a>; per le sponsorizzazioni <a href="{{ route('manuale') }}#sezione-10" class="link">§ 10</a>.</li>
        </ul>
    </details>
</section>

<section data-merlo="test-pareggio" id="test-pareggio">
    <h2>Pareggio per iniziativa (area A)</h2>
    <p class="nota">Indicatore informativo, non è il test di legge: confronta i corrispettivi di ogni iniziativa con i suoi costi diretti. Il test è quello aggregato sull’area A, qui sopra, e conta anche una quota delle spese generali. Serve a documentare scelte come la Festa della Torre in A7.</p>
    @forelse ($catena['pareggi'] as $p)
        <p id="pareggio-iniziativa-{{ $p['iniziativa'] }}" data-merlo="pareggio-iniziativa">
            <strong>{{ $p['nome'] }}</strong>: corrispettivi {{ $euro($p['corrispettivi']) }} €, costi diretti {{ $euro($p['costi']) }} €:
            @if ($p['differenza'] > 0)
                {{ $euro($p['differenza']) }} € sopra i costi diretti.
            @elseif ($p['differenza'] < 0)
                {{ $euro(-$p['differenza']) }} € sotto i costi diretti.
            @else
                in pareggio.
            @endif
        </p>
    @empty
        <p class="nota">Nessuna iniziativa ha imputazioni in area A in questo esercizio.</p>
    @endforelse
</section>

<section data-merlo="test-annotazione" id="test-annotazione">
    <h2>Annotazione in calce al rendiconto</h2>
    <p class="nota">Le dichiarazioni dovute con il rendiconto (art. 13 c. 6 CTS): criterio di secondarietà, strumentalità e criterio di riparto dei costi promiscui. Il testo è precompilato dagli esiti e segue i dati finché non lo correggi; l’impaginazione nel rendiconto è a parte.
        {{ $annotazione['salvata'] === null ? 'Testo proposto dall’app.' : 'Testo modificato a mano.' }}</p>
    @can('gestire_esercizio')
        <form method="POST" action="{{ route('test-esercizio.annotazione', $anno) }}">
            @csrf
            @method('PUT')
            @if ($proposte) <input type="hidden" name="proposte" value="1"> @endif
            <label>Testo dell’annotazione
                <textarea name="testo" rows="9" maxlength="5000" class="campo">{{ old('testo', $annotazione['testo']) }}</textarea>
            </label>
            @error('testo') <p class="errore" role="alert">{{ $message }}</p> @enderror
            <div class="modulo-in-riga">
                <button type="submit" class="bottone">Salva l’annotazione</button>
                <button type="submit" name="ripristina" value="1" class="bottone-piccolo" formnovalidate>Ripristina il testo proposto</button>
            </div>
        </form>
    @else
        <p style="white-space: pre-line">{{ $annotazione['testo'] }}</p>
    @endcan
</section>
