@extends('layouts.app')

@section('titolo', 'Approvazione '.$anno)

@section('contenuto')
    @php($vigente = $versioni->firstWhere('riaperta_il', null))
    <h1>Approvazione dell’esercizio {{ $anno }}</h1>
    <p class="nota">
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D</a> ·
        <a href="{{ route('esercizio.rendiconto', $anno) }}" class="link">Rendiconto per l’assemblea</a> ·
        <a href="{{ route('test-esercizio', $anno) }}" class="link">Test dell’esercizio</a> ·
        <a href="{{ route('esercizio.approvazione', $anno - 1) }}" class="link">{{ $anno - 1 }}</a> ·
        <a href="{{ route('esercizio.approvazione', $anno + 1) }}" class="link">{{ $anno + 1 }}</a>
    </p>

    @if ($storico)
        <p class="avviso" role="status">L’esercizio {{ $anno }} è storico: importi caricati dal foglio approvato, non si approva qui.</p>
    @else
        <p class="avviso" role="status" data-merlo="approvazione-stato">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>
                @if ($vigente?->depositata_il)
                    Depositato il {{ $vigente->depositata_il->format('d/m/Y') }} · versione {{ $vigente->numero }}, approvata il {{ $vigente->approvata_il->format('d/m/Y') }}. I prospetti sono l’istantanea del deposito e i movimenti non si toccano più: per correggere un errore l’Amministratore riapre l’esercizio.
                @elseif ($dati->approvato() && $vigente)
                    Approvato il {{ $vigente->approvata_il->format('d/m/Y') }} · versione {{ $vigente->numero }}. Le modifiche sono ammesse, con avviso, e i prospetti mostrano le differenze dalla versione approvata.
                @else
                    Aperto · versione {{ $dati->versione }}{{ $dati->versione > 1 ? ' (riaperto)' : '' }}: i prospetti sono provvisori.
                @endif
            </span>
        </p>

        <section data-merlo="approvazione-controllo">
            <h2>Elenco di controllo</h2>
            <p class="nota">Informa, non blocca: serve ad arrivare all’assemblea con i conti in ordine.</p>
            @forelse ($controllo as $voce)
                @if ($loop->first) <ul class="elenco"> @endif
                <li>{{ $voce['testo'] }}@if ($voce['link']) · <a href="{{ $voce['link'] }}" class="link">vedi</a>@endif</li>
                @if ($loop->last) </ul> @endif
            @empty
                <p class="vuoto">Niente da segnalare: tutti i movimenti sono confermati, i conti riconciliati, senza dubbi, giroconti completi e spese con giustificativo.</p>
            @endforelse
        </section>

        <section data-merlo="approvazione-chiusura">
            <h2>Saldi iniziali del {{ $anno + 1 }}</h2>
            <p class="nota">Si calcolano dalla chiusura del {{ $anno }}, al 31/12: non si inseriscono a mano.</p>
            <ul class="elenco">
                @foreach ($chiusura as $riga => $importo)
                    <li>{{ \App\Models\Conto::RIGHE_MODELLO_D[$riga] }}: {{ \App\Euro::formatta($importo) }} €</li>
                @endforeach
            </ul>
        </section>

        @unless ($dati->approvato())
            @php($criterio = $s['confermato'] ?? $s['proposto'])
            <section data-merlo="approvazione-modulo">
                <h2>Approva</h2>
                <p>Con l’approvazione si salva la versione approvata dei prospetti e dei test e si fissa il criterio di secondarietà:
                    <strong>criterio {{ $criterio }} ({{ \App\Esercizio\Secondarieta::formatta($s['criteri'][$criterio]['soglia']) }}%)</strong>,
                    {{ $s['confermato'] ? 'confermato dal Tesoriere' : 'proposto dall’app perché non è stato ancora confermato' }}
                    — esito: {{ \App\Esercizio\Secondarieta::ETICHETTE[$s['criteri'][$criterio]['esito']] }}.
                    @if ($s['rientro']) Dopo la secondarietà non rispettata dell’esercizio {{ $anno - 1 }} il criterio è lo stesso, con la soglia ridotta. @endif
                    @if ($s['criteri'][$criterio]['esito'] === 'non_rispettato')
                        Non rispettata: all’approvazione compare in home il promemoria della segnalazione al RUNTS entro 30 giorni, e l’esercizio {{ $anno + 1 }} dovrà rientrare con lo stesso criterio e una soglia ridotta.
                    @endif
                </p>
                @can('gestire_esercizio')
                    <form method="POST" action="{{ route('esercizio.approva', $anno) }}" class="modulo modulo-stretto">
                        @csrf
                        <label>Data della delibera dell’assemblea <input type="date" name="data" min="{{ $anno + 1 }}-01-01" max="{{ today()->toDateString() }}" value="{{ old('data') }}" required></label>
                        <button type="submit" class="bottone">Approva l’esercizio {{ $anno }}</button>
                    </form>
                @else
                    <p class="nota">Approva chi gestisce i dati dell’esercizio.</p>
                @endcan
            </section>
        @else
            @if ($vigente)
                <section data-merlo="approvazione-deposito">
                    <h2>Deposito al RUNTS</h2>
                    @if ($vigente->depositata_il)
                        <p>Depositato il {{ $vigente->depositata_il->format('d/m/Y') }}. Il PDF/A è quello dell’istantanea, identico a ciò che i prospetti mostrano.
                            <a href="{{ route('esporta.deposito', $anno) }}" class="link">Scarica il PDF/A depositato</a></p>
                    @else
                        <p>Il PDF/A di deposito (indice e Modello D) è il rendiconto approvato dall’assemblea, non i dati di oggi: firmalo con la firma CAdES (.p7m) e invialo al RUNTS. Ha già il nome finale, che il file firmato non può cambiare.
                            Poi inserisci qui la data dell’invio: l’esercizio passa a depositato, i prospetti si fissano e i movimenti non si toccano più.
                            <a href="{{ route('esporta.deposito', $anno) }}" class="link">Scarica il PDF/A di deposito</a></p>
                        @if (\App\Esercizio\Differenze::presenti($situazione['differenze'], ['voci', 'esiti', 'rendiconto']))
                            <p class="avviso" role="status">Dopo l’approvazione i dati sono cambiati: il PDF di deposito non li contiene. Per depositare le correzioni l’Amministratore riapre l’esercizio e lo si approva di nuovo.</p>
                        @endif
                        @can('gestire_esercizio')
                            <form method="POST" action="{{ route('esercizio.deposita', $anno) }}" class="modulo modulo-stretto">
                                @csrf
                                <label>Impronta del PDF (la trovi nel piede di ogni pagina) <input type="text" name="impronta" maxlength="20" autocomplete="off" value="{{ old('impronta') }}" required></label>
                                <label>Data dell’invio al RUNTS <input type="date" name="data" min="{{ $vigente->approvata_il->toDateString() }}" max="{{ today()->toDateString() }}" value="{{ old('data') }}" required></label>
                                <button type="submit" class="bottone">Deposita l’esercizio {{ $anno }}</button>
                            </form>
                        @else
                            <p class="nota">Deposita chi gestisce i dati dell’esercizio.</p>
                        @endcan
                    @endif
                </section>
            @endif
            @can('riaprire_esercizio')
                <section data-merlo="approvazione-riapertura">
                    <h2>Riapri</h2>
                    <p>Si riapre solo per correggere un errore: la versione approvata resta nello storico{{ $vigente?->depositata_il ? ', con il suo deposito,' : '' }} e l’approvazione successiva sarà la versione {{ $dati->versione + 1 }}. L’esito approvato non vale più per il conteggio della tolleranza del 6%, finché non si approva di nuovo.</p>
                    <form method="POST" action="{{ route('esercizio.riapri', $anno) }}" class="modulo modulo-stretto">
                        @csrf
                        <label>Motivazione <textarea name="motivazione" rows="3" maxlength="1000" required>{{ old('motivazione') }}</textarea></label>
                        <button type="submit" class="bottone">Riapri l’esercizio {{ $anno }}</button>
                    </form>
                </section>
            @endcan
        @endunless

        @if ($versioni->first()?->esito_secondarieta === 'non_rispettato') {{-- l'ultima versione, anche se riaperta: l'obbligo resta --}}
            @include('esercizio.segnalazione', ['versione' => $versioni->first()])
        @endif

        <section data-merlo="approvazione-storico">
            <h2>Versioni</h2>
            @forelse ($versioni as $v)
                @if ($loop->first) <ul class="schede"> @endif
                <li class="scheda">
                    <p><strong>Versione {{ $v->numero }}</strong> · approvata il {{ $v->approvata_il->format('d/m/Y') }} · criterio {{ $v->criterio_secondarieta }}, {{ \App\Esercizio\Secondarieta::ETICHETTE[$v->esito_secondarieta] }}</p>
                    @if ($v->depositata_il)
                        <p class="nota">Depositata il {{ $v->depositata_il->format('d/m/Y') }}.</p>
                    @endif
                    @if ($v->riaperta_il)
                        <p class="nota">Riaperta il {{ $v->riaperta_il->format('d/m/Y') }}: {{ $v->motivazione_riapertura }}</p>
                    @endif
                </li>
                @if ($loop->last) </ul> @endif
            @empty
                <p class="vuoto">Nessuna versione approvata.</p>
            @endforelse
        </section>
    @endif
@endsection
