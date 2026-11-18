{{-- I totali e gli avanzi che l'app ricalcola dagli importi di un esercizio storico (nessun link: non ci sono imputazioni dietro). --}}
@php($euro = fn (int $c) => \App\Euro::formatta($c))
<div class="tabella-scorrevole">
    <table class="tabella">
        <thead><tr><th scope="col">Riga</th><th scope="col" class="text-right">Importo (€)</th></tr></thead>
        <tbody>
            @foreach ($m['aree'] as $area => $sezione)
                <tr><td>{{ $area }}) Uscite da {{ lcfirst($sezione['titolo']) }}</td><td class="text-right">{{ $euro($sezione['uscite']['totale']['importo']) }}</td></tr>
                <tr><td>{{ $area }}) Entrate da {{ lcfirst($sezione['titolo']) }}</td><td class="text-right">{{ $euro($sezione['entrate']['totale']['importo']) }}</td></tr>
                <tr><td>Avanzo/disavanzo {{ lcfirst($sezione['titolo']) }}</td><td class="text-right">{{ $euro($sezione['avanzo']['importo']) }}</td></tr>
            @endforeach
            <tr><td>Totale uscite della gestione</td><td class="text-right">{{ $euro($m['gestione']['uscite']['importo']) }}</td></tr>
            <tr><td>Totale entrate della gestione</td><td class="text-right">{{ $euro($m['gestione']['entrate']['importo']) }}</td></tr>
            <tr><td>Avanzo/disavanzo d’esercizio prima delle imposte</td><td class="text-right">{{ $euro($m['gestione']['avanzo']['importo']) }}</td></tr>
            <tr><td>Imposte</td><td class="text-right">{{ $euro($m['imposte']['importo']) }}</td></tr>
            <tr><td>Avanzo/disavanzo da investimenti e disinvestimenti</td><td class="text-right">{{ $euro($m['investimenti']['avanzo']['importo']) }}</td></tr>
            <tr class="font-bold"><td>Avanzo/disavanzo complessivo</td><td class="text-right">{{ $euro($m['avanzoComplessivo']['importo']) }}</td></tr>
        </tbody>
    </table>
</div>
