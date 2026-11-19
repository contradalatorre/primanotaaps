{{-- Le esportazioni di un prospetto (#58). `$quale`: «modello-d» o «rendiconto». Il prospetto scaricato è quello che si vede, con la stessa scelta sulle proposte. --}}
@php($query = $proposte ? ['proposte' => 1] : [])
<p class="nota" data-merlo="esporta-{{ $quale }}">
    Scarica:
    <a href="{{ route('esporta.'.$quale, ['anno' => $anno, 'formato' => 'pdf'] + $query) }}" class="link">PDF</a> ·
    <a href="{{ route('esporta.'.$quale, ['anno' => $anno, 'formato' => 'xlsx'] + $query) }}" class="link">XLSX con il foglio Dettaglio</a> ·
    <a href="{{ route('esporta.elenco', ['anno' => $anno, 'tipo' => 'imputazioni'] + $query) }}" class="link">CSV delle imputazioni</a> ·
    <a href="{{ route('esporta.elenco', ['anno' => $anno, 'tipo' => 'movimenti']) }}" class="link">CSV dei movimenti</a>
</p>
