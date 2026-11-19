{{-- Un lato (entrate o uscite) di un gruppo del rendiconto: le righe per tipologia, poi il totale. --}}
<tr><th scope="rowgroup" colspan="{{ count($conti) + 2 }}" class="text-left">{{ $titolo }}</th></tr>
@foreach ($righe as $riga)
    <tr><td>{{ $riga['tipologia'] }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $riga])</tr>
@endforeach
<tr class="font-bold"><td>Totale {{ strtolower($titolo) }}</td>@include('esercizio.rendiconto-celle', ['cifra' => $totale])</tr>
