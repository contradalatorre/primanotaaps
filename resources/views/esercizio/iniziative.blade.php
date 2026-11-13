@extends('layouts.app')

@section('titolo', 'Conti per iniziativa '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Conti per iniziativa · {{ $anno }}</h1>
    <p class="nota">Entrate e uscite di ogni iniziativa, per tipologia, nell’esercizio scelto, nel precedente e dall’avvio (fino al {{ $anno }}). Fuori i giroconti; le commissioni di PayPal e Satispay restano in D1 (area D), senza iniziativa.
        {{ $proposte ? 'Con anche le imputazioni proposte, ancora da confermare.' : 'Solo le imputazioni confermate.' }}</p>

    <form method="GET" action="{{ route('esercizio.iniziative', $anno) }}" class="modulo-in-riga">
        <label class="spunta"><input type="checkbox" name="proposte" value="1" @checked($proposte) onchange="this.form.submit()"> <span>Includi anche le imputazioni proposte (la scelta non si salva)</span></label>
        <noscript><button type="submit" class="bottone">Aggiorna</button></noscript>
    </form>
    <p class="nota">
        <a href="{{ route('esercizio.iniziative', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('esercizio.iniziative', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a> ·
        <a href="{{ route('modello-d', $anno) }}" class="link">Modello D {{ $anno }}</a>
    </p>

    @forelse ($schede as $scheda)
        @php($i = $scheda['iniziativa'])
        @php($c = $scheda['conti'])
        <section data-merlo="iniziativa-{{ $i->id }}">
            <h2>{{ $i->nome }}{{ $i->archiviata() ? ' (archiviata)' : '' }}</h2>

            @unless ($c['conPreventivo'])
                <p class="nota">Nessun preventivo per il {{ $anno }}.</p>
            @endunless
            <div class="tabella-scorrevole">
                <table class="tabella">
                    <thead>
                        <tr>
                            <th scope="col">Tipologia</th><th scope="col" class="text-right">Esercizio {{ $anno }}</th><th scope="col" class="text-right">Esercizio {{ $anno - 1 }}</th><th scope="col" class="text-right">Dall’avvio</th>
                            @if ($c['conPreventivo']) <th scope="col" class="text-right">Preventivo {{ $anno }}</th><th scope="col" class="text-right">Scostamento</th> @endif
                        </tr>
                    </thead>
                    @foreach (['entrate' => 'Entrate', 'uscite' => 'Uscite'] as $lato => $titolo)
                        <tbody>
                            <tr><th scope="rowgroup" colspan="{{ $c['conPreventivo'] ? 6 : 4 }}" class="text-left">{{ $titolo }}</th></tr>
                            @foreach ($c['righe'][$lato] as $riga)
                                <tr>
                                    <td>{{ $riga['tipologia'] }}</td>
                                    <td class="text-right">{{ $euro($riga['effettivo']) }}</td><td class="text-right">{{ $euro($riga['precedente']) }}</td><td class="text-right">{{ $euro($riga['avvio']) }}</td>
                                    @if ($c['conPreventivo']) <td class="text-right">{{ $euro($riga['previsto']) }}</td><td class="text-right">{{ $euro($riga['scostamento']) }}</td> @endif
                                </tr>
                            @endforeach
                            <tr class="font-bold">
                                <td>Totale {{ strtolower($titolo) }}</td>
                                <td class="text-right">{{ $euro($c['colonne']['scelto'][$lato]) }}</td><td class="text-right">{{ $euro($c['colonne']['precedente'][$lato]) }}</td><td class="text-right">{{ $euro($c['colonne']['avvio'][$lato]) }}</td>
                                @if ($c['conPreventivo']) <td class="text-right">{{ $euro($c['totali'][$lato]['previsto']) }}</td><td class="text-right">{{ $euro($c['totali'][$lato]['scostamento']) }}</td> @endif
                            </tr>
                        </tbody>
                    @endforeach
                    <tbody>
                        <tr class="font-bold">
                            <td>Risultato</td>
                            <td class="text-right">{{ $euro($c['colonne']['scelto']['risultato']) }}</td><td class="text-right">{{ $euro($c['colonne']['precedente']['risultato']) }}</td><td class="text-right">{{ $euro($c['colonne']['avvio']['risultato']) }}</td>
                            @if ($c['conPreventivo']) <td class="text-right">{{ $euro($c['totali']['risultato']['previsto']) }}</td><td class="text-right">{{ $euro($c['totali']['risultato']['scostamento']) }}</td> @endif
                        </tr>
                    </tbody>
                </table>
            </div>
            @if ($c['conPreventivo'])
                <p class="nota">Scostamento = effettivo − preventivo, per l’esercizio {{ $anno }}: nelle uscite un valore positivo è una spesa oltre il preventivo.</p>
            @endif

            @if ($scheda['inAreaA'] && Route::has('esercizio.test'))
                <p class="nota">Indicatore di pareggio dell’area A (informativo, non è il test di legge): <a href="{{ route('esercizio.test', $anno) }}#pareggio-iniziativa-{{ $i->id }}" class="link">vedi nei test</a>.</p>
            @endif
            @can('gestire_regole')
                <p><a href="{{ route('esercizio.preventivo', [$anno, $i]) }}" class="link">{{ $c['conPreventivo'] ? 'Modifica' : 'Scrivi' }} il preventivo {{ $anno }} di {{ $i->nome }}</a></p>
            @endcan
        </section>
    @empty
        <p class="vuoto">Nessuna iniziativa ha imputazioni fino al {{ $anno }} né un preventivo per il {{ $anno }}.</p>
    @endforelse
@endsection
