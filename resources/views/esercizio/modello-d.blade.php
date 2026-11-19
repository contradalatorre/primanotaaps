@extends('layouts.app')

@section('titolo', 'Modello D '.$anno)

@section('contenuto')
    @php($i = $m['intestazione'])
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Modello D · Rendiconto per cassa {{ $anno }}</h1>
    <p><strong>{{ $i['denominazione'] ?? 'Denominazione da inserire' }}</strong>
        · codice fiscale {{ $i['codice_fiscale'] ?? 'da inserire' }} · numero RUNTS {{ $i['numero_runts'] ?? 'da inserire' }}
        @can('gestire_impostazioni') <a href="{{ route('impostazioni') }}" class="link">Impostazioni</a> @endcan
    </p>
    @if ($storico)
        <p class="avviso" role="status" data-merlo="modello-d-storico">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Esercizio storico: importi caricati dal foglio approvato, senza movimenti. Totali e avanzi sono ricalcolati dall’app. <a href="{{ route('esercizio.storico', $anno) }}" class="link">Dettagli e differenze dal foglio</a></span>
        </p>
    @else
        <p class="avviso" role="status" data-merlo="modello-d-provvisorio">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Provvisorio – aggiornato al {{ $aggiornatoAl->format('d/m/Y H:i') }}. {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }}</span>
        </p>

        <form method="GET" action="{{ route('modello-d', $anno) }}" class="modulo-in-riga">
            <label class="spunta"><input type="checkbox" name="proposte" value="1" @checked($proposte) onchange="this.form.submit()"> <span>Includi anche le imputazioni proposte (la scelta non si salva)</span></label>
            <noscript><button type="submit" class="bottone">Aggiorna</button></noscript>
        </form>
    @endif
    <p class="nota">
        <a href="{{ route('modello-d', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('modello-d', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a> ·
        <a href="{{ route('esercizio.iniziative', $anno) }}" class="link">Conti per iniziativa {{ $anno }}</a> ·
        <a href="{{ route('esercizio.rendiconto', $anno) }}" class="link">Rendiconto per l’assemblea {{ $anno }}</a>
        @can('gestire_esercizio') · <a href="{{ route('esercizio.saldi', $anno) }}" class="link">Saldi di apertura {{ $anno }}</a> @endcan
    </p>
    @include('esercizio.esporta', ['quale' => 'modello-d'])

    @if (! $precedente && $storicoCaricabile)
        <p class="nota" data-merlo="modello-d-senza-precedente">Esercizio storico {{ $anno - 1 }} non caricato: la colonna dell’esercizio precedente resta vuota.
            @can('gestire_esercizio') <a href="{{ route('esercizio.storico', $anno - 1) }}" class="link">Caricalo dal foglio approvato</a> @endcan
        </p>
    @endif

    @if (! $storico && ! $vociCaricate)
        <p class="avviso" role="status" data-merlo="modello-d-senza-voci">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Per il {{ $anno }} la tabella delle voci del Modello D non è caricata: nessun movimento di quell’anno si può classificare.
                @if ($sorgenteApertura)
                    @can('riclassificare_in_blocco')
                        <form method="POST" action="{{ route('esercizio.apri', $anno) }}" class="inline">@csrf <button type="submit" class="bottone">Apri il {{ $anno }}: copia voci e predefiniti dal {{ $sorgenteApertura }}</button></form>
                    @else
                        Chi può modificare la tabella delle voci può copiarla dal {{ $sorgenteApertura }}.
                    @endcan
                @endif
            </span>
        </p>
    @endif

    @unless ($storico)
    <section data-merlo="modello-d-impedimenti">
        <h2>Impedimenti</h2>
        @forelse ($impedimenti as $impedimento)
            @if ($loop->first) <ul class="elenco"> @endif
            <li>{{ $impedimento['testo'] }}@if ($impedimento['link']) · <a href="{{ $impedimento['link'] }}" class="link">vedi</a>@endif</li>
            @if ($loop->last) </ul> @endif
        @empty
            <p class="vuoto">Nessun impedimento: tutti i movimenti sono confermati, i conti riconciliati e senza dubbi.</p>
        @endforelse
        @foreach ($m['anomalie'] as $anomalia)
            <p class="avviso" role="status">{{ $anomalia }}</p>
        @endforeach
        @if ($m['senzaRiga']['entrate'] + $m['senzaRiga']['uscite'] > 0)
            <p><a href="{{ route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => \App\Esercizio\Prospetto::SENZA_RIGA, 'titolo' => 'Senza riga nel Modello D'] + ($proposte ? ['proposte' => 1] : [])) }}" class="link">Vedi le imputazioni senza riga nel Modello D</a></p>
        @endif
    </section>

    @include('esercizio.forzature-link', ['anno' => $anno])
    @endunless

    @foreach ($m['aree'] as $area => $sezione)
        <section data-merlo="modello-d-area-{{ $area }}">
            <h2>{{ $area }}) {{ $sezione['titolo'] }}</h2>
            @include('esercizio.blocco', ['blocco' => $sezione['uscite'], 'prec' => $precedente['aree'][$area]['uscite'] ?? null])
            @include('esercizio.blocco', ['blocco' => $sezione['entrate'], 'prec' => $precedente['aree'][$area]['entrate'] ?? null])
            <p class="font-bold">Avanzo/disavanzo {{ lcfirst($sezione['titolo']) }}: @include('esercizio.cifra', ['cifra' => $sezione['avanzo'], 'titolo' => 'Avanzo/disavanzo '.$area, 'sempre' => true]) €@if ($precedente) · esercizio {{ $anno - 1 }}: {{ $euro($precedente['aree'][$area]['avanzo']['importo']) }} €@endif</p>
        </section>
    @endforeach

    <section data-merlo="modello-d-riepilogo">
        <h2>Totali e avanzi</h2>
        <div class="tabella-scorrevole">
            <table class="tabella">
                @if ($precedente)<thead><tr><th scope="col">Riga</th><th scope="col" class="text-right">Importo (€)</th><th scope="col" class="text-right">Esercizio {{ $anno - 1 }} (€)</th></tr></thead>@endif
                <tbody>
                    @foreach ([
                        ['Totale uscite della gestione', $m['gestione']['uscite'], $precedente['gestione']['uscite'] ?? null],
                        ['Totale entrate della gestione', $m['gestione']['entrate'], $precedente['gestione']['entrate'] ?? null],
                        ['Avanzo/disavanzo d’esercizio prima delle imposte', $m['gestione']['avanzo'], $precedente['gestione']['avanzo'] ?? null],
                        ['Imposte', $m['imposte'], $precedente['imposte'] ?? null],
                        ['Avanzo/disavanzo d’esercizio prima degli investimenti e disinvestimenti patrimoniali e finanziamenti', $m['avanzoPrimaDegliInvestimenti'], $precedente['avanzoPrimaDegliInvestimenti'] ?? null],
                    ] as [$etichetta, $cifra, $prec])
                        <tr><td>{{ $etichetta }}</td><td class="text-right">@include('esercizio.cifra', ['cifra' => $cifra, 'titolo' => $etichetta, 'sempre' => str_starts_with($etichetta, 'Avanzo')])</td>@if ($precedente)<td class="text-right">{{ $euro($prec['importo']) }}</td>@endif</tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @include('esercizio.blocco', ['blocco' => $m['investimenti']['uscite'], 'prec' => $precedente['investimenti']['uscite'] ?? null])
        @include('esercizio.blocco', ['blocco' => $m['investimenti']['entrate'], 'prec' => $precedente['investimenti']['entrate'] ?? null])
        <div class="tabella-scorrevole">
            <table class="tabella">
                <tbody>
                    <tr><td>Avanzo/disavanzo da investimenti e disinvestimenti patrimoniali e finanziamenti</td><td class="text-right">@include('esercizio.cifra', ['cifra' => $m['investimenti']['avanzo'], 'titolo' => 'Investimenti e disinvestimenti', 'sempre' => true])</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['investimenti']['avanzo']['importo']) }}</td>@endif</tr>
                    <tr class="font-bold"><td>Avanzo/disavanzo complessivo</td><td class="text-right">@include('esercizio.cifra', ['cifra' => $m['avanzoComplessivo'], 'titolo' => 'Avanzo/disavanzo complessivo', 'sempre' => true])</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['avanzoComplessivo']['importo']) }}</td>@endif</tr>
                </tbody>
            </table>
        </div>
    </section>

    <section data-merlo="modello-d-liquidita">
        <h2>Cassa e banca</h2>
        <p class="nota">Il saldo dei conti al 31/12/{{ $anno }}: cassa i contanti, depositi bancari e postali gli altri conti (<a href="{{ route('conti') }}" class="link">elenco dei conti</a>).</p>
        <div class="tabella-scorrevole">
            <table class="tabella">
                @if ($precedente)<thead><tr><th scope="col">Riga</th><th scope="col" class="text-right">Al 31/12/{{ $anno }} (€)</th><th scope="col" class="text-right">Al 31/12/{{ $anno - 1 }} (€)</th></tr></thead>@endif
                <tbody>
                    <tr><td>Cassa</td><td class="text-right">{{ $euro($m['liquidita']['cassa']) }}</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['liquidita']['cassa']) }}</td>@endif</tr>
                    <tr><td>Depositi bancari e postali</td><td class="text-right">{{ $euro($m['liquidita']['depositi']) }}</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['liquidita']['depositi']) }}</td>@endif</tr>
                    <tr class="font-bold"><td>Totale</td><td class="text-right">{{ $euro($m['liquidita']['totale']) }}</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['liquidita']['totale']) }}</td>@endif</tr>
                </tbody>
            </table>
        </div>
        @if ($storico)
            {{-- Un esercizio storico non ha i saldi iniziali: la quadratura non si può calcolare. --}}
        @elseif ($m['quadratura']['quadra'])
            <p class="nota" data-merlo="modello-d-quadra">Quadra: la liquidità è cambiata esattamente dell’avanzo complessivo.</p>
        @else
            <p class="avviso" role="status" data-merlo="modello-d-non-quadra">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                <span>Non quadra: la liquidità è passata da {{ $euro($m['liquidita']['iniziale']) }} € a {{ $euro($m['liquidita']['totale']) }} €, ma l’avanzo complessivo è {{ $euro($m['avanzoComplessivo']['importo']) }} €
                    (differenza di {{ $euro($m['quadratura']['differenza']) }} €). Durante l’anno dipende di solito da movimenti non ancora confermati o da giroconti a metà; a fine anno va spiegata.</span>
            </p>
        @endif
    </section>

    <section data-merlo="modello-d-figurativi">
        <h2>Costi e proventi figurativi</h2>
        <p class="nota">Dai dati dell’esercizio: solo ore effettivamente rilevate, mai stime. Non entrano in nessun totale né avanzo.</p>
        @foreach (['costi' => 'Costi figurativi', 'proventi' => 'Proventi figurativi'] as $chiave => $titolo)
            <div class="tabella-scorrevole">
                <table class="tabella">
                    <caption class="text-left font-bold">{{ $titolo }}</caption>
                    @if ($precedente)<thead><tr><th scope="col">Riga</th><th scope="col" class="text-right">{{ $anno }} (€)</th><th scope="col" class="text-right">{{ $anno - 1 }} (€)</th></tr></thead>@endif
                    <tbody>
                        @foreach ($m['figurativi'][$chiave]['righe'] as $riga)
                            <tr>
                                <td>{{ $riga['etichetta'] }}</td>
                                <td class="text-right">{{ $euro($riga['importo']) }}</td>
                                @if ($precedente)<td class="text-right">{{ $euro($precedente['figurativi'][$chiave]['righe'][$loop->index]['importo']) }}</td>@endif
                            </tr>
                        @endforeach
                        <tr class="font-bold"><td>Totale</td><td class="text-right">{{ $euro($m['figurativi'][$chiave]['totale']) }}</td>@if ($precedente)<td class="text-right">{{ $euro($precedente['figurativi'][$chiave]['totale']) }}</td>@endif</tr>
                    </tbody>
                </table>
            </div>
        @endforeach
    </section>
@endsection
