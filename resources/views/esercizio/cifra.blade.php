{{-- Una cifra del Modello D: porta all'elenco delle imputazioni che la compongono. Se è zero il link c'è solo per gli avanzi (`sempre`): una differenza può essere zero con delle imputazioni dietro. --}}
@if ($cifra['importo'] !== 0 || ($sempre ?? false))
    <a href="{{ route('esercizio.imputazioni', ['anno' => $anno, 'chiavi' => implode(',', $cifra['chiavi']), 'titolo' => $titolo] + ($proposte ? ['proposte' => 1] : [])) }}" class="link">{{ \App\Euro::formatta($cifra['importo']) }}</a>
@else
    {{ \App\Euro::formatta($cifra['importo']) }}
@endif
