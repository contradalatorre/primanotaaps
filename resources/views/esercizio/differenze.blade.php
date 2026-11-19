{{-- Le differenze dalla versione approvata (#59): le voci del Modello D e i test che oggi sono diversi da come l'assemblea li ha approvati. Vuole $differenze (`Differenze::tra`). --}}
@php($euro = fn (?int $c) => $c === null ? '—' : \App\Euro::formatta($c))
@php($esito = fn (?string $e) => $e === null ? '—' : (\App\Esercizio\Differenze::ESITI[$e] ?? $e))
<section data-merlo="differenze-approvata">
    <h2>Differenze dalla versione approvata</h2>
    <p class="nota">Confronto con le sole imputazioni confermate, come nell’approvazione.</p>
    @if ($differenze['voci'] === [] && $differenze['esiti'] === [])
        <p class="vuoto">Nessuna differenza: i prospetti e i test sono come li ha approvati l’assemblea.</p>
    @else
        @if ($differenze['voci'] !== [])
            <div class="tabella-scorrevole">
                <table class="tabella">
                    <thead><tr><th scope="col">Voce</th><th scope="col" class="text-right">Approvata (€)</th><th scope="col" class="text-right">Oggi (€)</th><th scope="col" class="text-right">Differenza (€)</th></tr></thead>
                    <tbody>
                        @foreach ($differenze['voci'] as $riga)
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
        @if ($differenze['esiti'] !== [])
            <ul class="elenco">
                @foreach ($differenze['esiti'] as $riga)
                    <li>{{ $riga['etichetta'] }}: approvato «{{ $esito($riga['approvato']) }}», oggi «{{ $esito($riga['attuale']) }}»</li>
                @endforeach
            </ul>
        @endif
    @endif
</section>
