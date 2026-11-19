@extends('layouts.app')

@section('titolo', 'Rendiconto per l’assemblea '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Rendiconto per l’assemblea · {{ $anno }}</h1>
    <p class="nota">
        <a href="{{ route('esercizio.rendiconto', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('esercizio.rendiconto', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a> ·
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a> ·
        <a href="{{ route('esercizio.iniziative', $anno) }}" class="link">Conti per iniziativa {{ $anno }}</a>
    </p>
    @unless ($storico)
        @include('esercizio.esporta', ['quale' => 'rendiconto'])
    @endunless

    @if ($storico)
        <p class="avviso" role="status" data-merlo="rendiconto-storico">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Esercizio storico: senza movimenti non c’è il rendiconto per l’assemblea, solo il <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a> dagli importi del foglio approvato.</span>
        </p>
    @else
        @include('esercizio.stato-prospetto', ['merlo' => 'rendiconto-provvisorio', 'nota' => 'Fuori i giroconti: compaiono solo nel riepilogo per conto.'])

        <form method="GET" action="{{ route('esercizio.rendiconto', $anno) }}" class="modulo-in-riga">
            <label class="spunta"><input type="checkbox" name="proposte" value="1" @checked($proposte) onchange="this.form.submit()"> <span>Includi anche le imputazioni proposte (la scelta non si salva)</span></label>
            <label class="spunta"><input type="checkbox" name="per" value="voce" @checked($perVoce) onchange="this.form.submit()"> <span>Raggruppa per voce del Modello D, poi per tipologia (vista di controllo)</span></label>
            <noscript><button type="submit" class="bottone">Aggiorna</button></noscript>
        </form>

        <section data-merlo="rendiconto-prospetto">
            <h2>{{ $perVoce ? 'Per voce del Modello D' : 'Per area, attività, iniziativa e tipologia' }}</h2>
            @if ($r['aree'] === [])
                <p class="vuoto">Nessuna imputazione nell’esercizio {{ $anno }}.</p>
            @else
                <div class="tabella-scorrevole">
                    <table class="tabella">
                        <thead>
                            <tr>
                                <th scope="col">{{ $perVoce ? 'Voce e tipologia' : 'Area, attività, iniziativa e tipologia' }}</th>
                                @foreach ($r['conti'] as $nome)<th scope="col" class="text-right">{{ $nome }}</th>@endforeach
                                <th scope="col" class="text-right">Totale</th>
                            </tr>
                        </thead>
                        @if ($perVoce)
                            @foreach (['entrate' => 'Entrate', 'uscite' => 'Uscite'] as $lato => $titolo)
                                <tbody data-merlo="rendiconto-voci-{{ $lato }}">
                                    <tr><th scope="rowgroup" colspan="{{ count($r['conti']) + 2 }}" class="text-left">{{ $titolo }}</th></tr>
                                    @foreach (array_filter($r['voci'], fn ($v) => $v['lato'] === $lato) as $voce)
                                        <tr class="font-bold"><td>{{ $voce['voce'] === null ? '' : $voce['voce'].') ' }}{{ $voce['etichetta'] }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $voce['totale']])</tr>
                                        @foreach ($voce['righe'] as $riga)
                                            <tr><td>– {{ $riga['tipologia'] }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $riga])</tr>
                                        @endforeach
                                    @endforeach
                                    <tr class="font-bold"><td>Totale {{ strtolower($titolo) }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $r['totali'][$lato]])</tr>
                                </tbody>
                            @endforeach
                        @else
                            @foreach ($r['aree'] as $codice => $area)
                                <tbody data-merlo="rendiconto-area-{{ $codice }}">
                                    <tr><th scope="rowgroup" colspan="{{ count($r['conti']) + 2 }}" class="text-left">Area {{ $codice }} · {{ $area['titolo'] }}</th></tr>
                                    @foreach ($area['attivita'] as $attivita)
                                        <tr><th scope="rowgroup" colspan="{{ count($r['conti']) + 2 }}" class="text-left">{{ $attivita['nome'] }}</th></tr>
                                        @foreach ($attivita['iniziative'] as $iniziativa)
                                            <tr><th scope="rowgroup" colspan="{{ count($r['conti']) + 2 }}" class="text-left">{{ $iniziativa['nome'] }}</th></tr>
                                            @include('esercizio.rendiconto-lato', ['titolo' => 'Entrate', 'righe' => $iniziativa['entrate']['righe'], 'totale' => $iniziativa['entrate']['totale'], 'conti' => $r['conti']])
                                            @include('esercizio.rendiconto-lato', ['titolo' => 'Uscite', 'righe' => $iniziativa['uscite']['righe'], 'totale' => $iniziativa['uscite']['totale'], 'conti' => $r['conti']])
                                            <tr class="font-bold"><td>Risultato · {{ $iniziativa['nome'] }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $iniziativa['risultato']])</tr>
                                        @endforeach
                                        <tr class="font-bold"><td>Risultato dell’attività · {{ $attivita['nome'] }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $attivita['risultato']])</tr>
                                    @endforeach
                                    <tr class="font-bold"><td>Entrate dell’area {{ $codice }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $area['entrate']])</tr>
                                    <tr class="font-bold"><td>Uscite dell’area {{ $codice }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $area['uscite']])</tr>
                                    <tr class="font-bold"><td>Risultato dell’area {{ $codice }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $area['risultato']])</tr>
                                </tbody>
                            @endforeach
                        @endif
                        <tbody data-merlo="rendiconto-totali">
                            <tr class="font-bold"><td>Totale entrate</td>@include('esercizio.rendiconto-celle', ['cifra' => $r['totali']['entrate']])</tr>
                            <tr class="font-bold"><td>Totale uscite</td>@include('esercizio.rendiconto-celle', ['cifra' => $r['totali']['uscite']])</tr>
                            <tr class="font-bold"><td>Avanzo (disavanzo) dell’esercizio</td>@include('esercizio.rendiconto-celle', ['cifra' => $r['totali']['risultato']])</tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        @foreach ($r['senzaRiga']['avvisi'] as $avviso)
            <p class="avviso" role="status" data-merlo="rendiconto-senza-riga">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                <span>{{ $avviso }}</span>
            </p>
        @endforeach
        @if ($r['senzaRiga']['avvisi'] !== [])
            <p class="nota"><a href="{{ route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => \App\Esercizio\Prospetto::SENZA_RIGA, 'titolo' => 'Senza riga nel Modello D'] + ($proposte ? ['proposte' => 1] : [])) }}" class="link">Vedi le imputazioni senza riga nel Modello D</a></p>
        @endif

        <section data-merlo="rendiconto-riepilogo">
            <h2>Riepilogo per conto</h2>
            <div class="tabella-scorrevole">
                <table class="tabella">
                    <thead>
                        <tr>
                            <th scope="col">Conto</th><th scope="col" class="text-right">Saldo iniziale</th><th scope="col" class="text-right">Entrate</th><th scope="col" class="text-right">Uscite</th>
                            <th scope="col" class="text-right">Giroconti in entrata</th><th scope="col" class="text-right">Giroconti in uscita</th><th scope="col" class="text-right">Saldo finale</th>
                            <th scope="col" class="text-right">Non classificato</th><th scope="col" class="text-right">Saldo riconciliato</th><th scope="col">Riconciliato</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($r['riepilogo']['conti'] as $id => $c)
                            <tr data-merlo="rendiconto-conto-{{ $id }}">
                                <td>{{ $c['nome'] }}</td>
                                <td class="text-right">{{ $euro($c['iniziale']) }}</td><td class="text-right">{{ $euro($c['entrate']) }}</td><td class="text-right">{{ $euro($c['uscite']) }}</td>
                                <td class="text-right">{{ $euro($c['giroconti_entrata']) }}</td><td class="text-right">{{ $euro($c['giroconti_uscita']) }}</td><td class="text-right">{{ $euro($c['finale']) }}</td>
                                <td class="text-right">{{ $euro($c['nonClassificato']) }}</td>
                                <td class="text-right">{{ $c['saldoRiconciliato'] === null ? '–' : $euro($c['saldoRiconciliato']) }}</td>
                                <td>{{ $c['riconciliatoAl'] === null ? 'mai' : ($c['riconciliato'] ? 'sì' : 'fino al '.date('d/m/Y', strtotime($c['riconciliatoAl']))) }}@if ($c['differenzaRiconciliato'] !== null && ! $c['riconciliato']) · differenza {{ $euro($c['differenzaRiconciliato']) }} @endif</td>
                            </tr>
                        @endforeach
                        @php($t = $r['riepilogo']['totale'])
                        <tr class="font-bold">
                            <td>Totale</td>
                            <td class="text-right">{{ $euro($t['iniziale']) }}</td><td class="text-right">{{ $euro($t['entrate']) }}</td><td class="text-right">{{ $euro($t['uscite']) }}</td>
                            <td class="text-right">{{ $euro($t['giroconti_entrata']) }}</td><td class="text-right">{{ $euro($t['giroconti_uscita']) }}</td><td class="text-right">{{ $euro($t['finale']) }}</td>
                            <td class="text-right">{{ $euro($t['nonClassificato']) }}</td><td></td><td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="nota">Saldo finale = saldo iniziale + entrate − uscite + giroconti in entrata − giroconti in uscita + non classificato. I giroconti non sono né entrate né uscite: restano fuori dai totali del prospetto. «Non classificato» è il denaro che le imputazioni non spiegano: movimenti ancora da classificare o da confermare, o la metà di un giroconto non confermato.</p>
            @foreach ($r['riepilogo']['conti'] as $c)
                @if ($c['avviso'])
                    <p class="avviso" role="status" data-merlo="rendiconto-avviso">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
                        <span>{{ $c['avviso'] }}.</span>
                    </p>
                @endif
            @endforeach
        </section>

        <section data-merlo="rendiconto-destinazione">
            <h2>Destinazione dell’avanzo</h2>
            @if ($r['destinazione']['righe'] === [])
                <p class="vuoto">Nessuna destinazione inserita.</p>
            @else
                <div class="tabella-scorrevole">
                    <table class="tabella">
                        <thead><tr><th scope="col">Destinazione</th><th scope="col" class="text-right">Importo (€)</th>@can('gestire_esercizio')<th scope="col">Nota</th>@endcan</tr></thead>
                        <tbody>
                            @foreach ($r['destinazione']['righe'] as $d)
                                <tr><td>{{ $d['descrizione'] }}</td><td class="text-right">{{ $euro($d['importo']) }}</td>@can('gestire_esercizio')<td>{{ $d['nota'] }}</td>@endcan</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <dl class="dati">
                <dt>Avanzo (disavanzo) dell’esercizio</dt><dd data-merlo="destinazione-avanzo">{{ $euro($r['destinazione']['avanzo']) }} €</dd>
                <dt>Destinato</dt><dd data-merlo="destinazione-destinato">{{ $euro($r['destinazione']['destinato']) }} €</dd>
                <dt>Residuo</dt><dd data-merlo="destinazione-residuo">{{ $euro($r['destinazione']['residuo']) }} €</dd>
            </dl>
            @can('gestire_esercizio')
                <p class="nota"><a href="{{ route('dati-esercizio', $anno) }}#destinazione" class="link">Scrivi la destinazione dell’avanzo tra i dati dell’esercizio {{ $anno }}</a>. Compare solo in questo prospetto, mai nel Modello D.</p>
            @endcan
        </section>
    @endif
@endsection
