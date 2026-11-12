{{-- Una cifra del Modello D: se non è zero porta all'elenco delle imputazioni che la compongono. --}}
@if ($cifra['importo'] !== 0)
    <a href="{{ route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => implode(',', $cifra['chiavi']), 'titolo' => $titolo] + ($proposte ? ['proposte' => 1] : [])) }}" class="link">{{ \App\Euro::formatta($cifra['importo']) }}</a>
@else
    {{ \App\Euro::formatta($cifra['importo']) }}
@endif
