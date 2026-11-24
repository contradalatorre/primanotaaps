{{-- Le celle di una cifra del rendiconto per l'assemblea: una per conto, poi il totale. --}}
@foreach ($cifra['conti'] as $importo)<td class="text-right">{{ \App\Euro::formatta($importo) }}</td>@endforeach
<td class="text-right font-bold">{{ \App\Euro::formatta($cifra['totale']) }}</td>
