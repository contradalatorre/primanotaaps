{{-- Un lato di una sezione del Modello D: le righe (sempre tutte, anche a zero) e il totale. `$prec` (facoltativo): lo stesso blocco dell'esercizio precedente, per la sua colonna (#63). --}}
@php($prec ??= null)
<div class="tabella-scorrevole">
    <table class="tabella">
        <caption class="text-left font-bold">{{ $blocco['titolo'] }}</caption>
        <thead><tr><th scope="col">Voce</th><th scope="col" class="text-right">Importo (€)</th>@if ($prec)<th scope="col" class="text-right">Esercizio {{ $anno - 1 }} (€)</th>@endif</tr></thead>
        <tbody>
            @foreach ($blocco['righe'] as $riga)
                <tr>
                    <td>{{ $riga['voce'] }}) {{ $riga['etichetta'] }}</td>
                    <td class="text-right">@include('esercizio.cifra', ['cifra' => $riga, 'titolo' => $riga['voce'].' · '.$riga['etichetta']])</td>
                    @if ($prec)<td class="text-right">{{ \App\Euro::formatta($prec['righe'][$loop->index]['importo']) }}</td>@endif
                </tr>
            @endforeach
            <tr class="font-bold">
                <td>Totale</td>
                <td class="text-right">@include('esercizio.cifra', ['cifra' => $blocco['totale'], 'titolo' => $blocco['titolo']])</td>
                @if ($prec)<td class="text-right">{{ \App\Euro::formatta($prec['totale']['importo']) }}</td>@endif
            </tr>
        </tbody>
    </table>
</div>
