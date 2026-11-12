{{-- Un lato di una sezione del Modello D: le righe (sempre tutte, anche a zero) e il totale. --}}
<div class="tabella-scorrevole">
    <table class="tabella">
        <caption class="text-left font-bold">{{ $blocco['titolo'] }}</caption>
        <thead><tr><th scope="col">Voce</th><th scope="col" class="text-right">Importo (€)</th></tr></thead>
        <tbody>
            @foreach ($blocco['righe'] as $riga)
                <tr>
                    <td>{{ $riga['voce'] }}) {{ $riga['etichetta'] }}</td>
                    <td class="text-right">@include('esercizio.cifra', ['cifra' => $riga, 'titolo' => $riga['voce'].' · '.$riga['etichetta']])</td>
                </tr>
            @endforeach
            <tr class="font-bold">
                <td>Totale</td>
                <td class="text-right">@include('esercizio.cifra', ['cifra' => $blocco['totale'], 'titolo' => $blocco['titolo']])</td>
            </tr>
        </tbody>
    </table>
</div>
