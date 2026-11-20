{{-- Le differenze dalla versione approvata (#59): le voci del Modello D, le cifre del rendiconto e i test che oggi sono diversi da come l'assemblea li ha approvati. Vuole $differenze (`Differenze::tra`); facoltativo $gruppi: quali mostrare (`voci`, `esiti`, `rendiconto`), per quel che la pagina mostra. --}}
@php($gruppi ??= ['voci', 'esiti'])
@php($euro = fn (?int $c) => $c === null ? '—' : \App\Euro::formatta($c))
@php($esito = fn (?string $e) => $e === null ? '—' : (\App\Esercizio\Secondarieta::ETICHETTE[$e] ?? $e))
<section data-merlo="differenze-approvata">
    <h2>Differenze dalla versione approvata</h2>
    <p class="nota">Confronto con le sole imputazioni confermate, come nell’approvazione.</p>
    @if (! \App\Esercizio\Differenze::presenti($differenze, $gruppi))
        <p class="vuoto">Nessuna differenza: i prospetti e i test sono come li ha approvati l’assemblea.</p>
    @else
        @foreach (array_intersect(['voci', 'rendiconto'], $gruppi) as $gruppo)
            @if ($differenze[$gruppo] !== [])
                <div class="tabella-scorrevole">
                    <table class="tabella">
                        <thead><tr><th scope="col">{{ $gruppo === 'voci' ? 'Voce' : 'Cifra del rendiconto' }}</th><th scope="col" class="text-right">Approvata (€)</th><th scope="col" class="text-right">Oggi (€)</th><th scope="col" class="text-right">Differenza (€)</th></tr></thead>
                        <tbody>
                            @foreach ($differenze[$gruppo] as $riga)
                                <tr>
                                    <td>{{ $riga['etichetta'] }}</td>
                                    <td class="text-right">{{ $euro($riga['approvato']) }}</td>
                                    <td class="text-right">{{ $euro($riga['attuale']) }}</td>
                                    <td class="text-right">{{ $euro(($riga['attuale'] ?? 0) - ($riga['approvato'] ?? 0)) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endforeach
        @if (in_array('esiti', $gruppi, true) && $differenze['esiti'] !== [])
            <ul class="elenco">
                @foreach ($differenze['esiti'] as $riga)
                    <li>{{ $riga['etichetta'] }}: approvato «{{ $esito($riga['approvato']) }}», oggi «{{ $esito($riga['attuale']) }}»</li>
                @endforeach
            </ul>
        @endif
    @endif
</section>
