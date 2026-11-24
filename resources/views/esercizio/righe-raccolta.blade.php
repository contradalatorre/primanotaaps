@extends('layouts.app')

@section('titolo', 'Righe delle raccolte '.$anno)

@section('contenuto')
    <h1>Righe delle raccolte fondi · {{ $anno }}</h1>
    <p class="nota">La tabella dice in quale riga dello schema delle raccolte occasionali (proventi e oneri) va ogni tipologia. Una tipologia con la voce C1 in area C e senza riga lascia la quadratura con la voce C2 sbagliata, e l’esercizio non si approva. Ogni cambiamento va nel registro delle modifiche.
        <a href="{{ route('esercizio.raccolte', $anno) }}" class="link">Raccolte {{ $anno }}</a> ·
        <a href="{{ route('esercizio.raccolte.righe', $anno - 1) }}" class="link">Esercizio {{ $anno - 1 }}</a> ·
        <a href="{{ route('esercizio.raccolte.righe', $anno + 1) }}" class="link">Esercizio {{ $anno + 1 }}</a></p>
    @if ($approvato)
        <p class="avviso" role="status">L’esercizio {{ $anno }} è approvato: la tabella non si cambia più. Per cambiarla l’Amministratore riapre l’esercizio (<a href="{{ route('esercizio.approvazione', $anno) }}" class="link">vedi l’approvazione</a>).</p>
    @elseif (! $aperto)
        <p class="avviso" role="status">L’esercizio {{ $anno }} non è ancora aperto: la tabella si copia dall’esercizio precedente con l’apertura, e solo dopo si cambia.</p>
    @endif

    <section data-merlo="righe-raccolta-elenco">
        <ul class="schede">
            @forelse ($tipologie as $t)
                @php($riga = $righe[$t->id] ?? null)
                @php($scelte = $t->verso === 'entrata' ? \App\Esercizio\Raccolta::PROVENTI : \App\Esercizio\Raccolta::ONERI)
                <li class="scheda">
                    <p><strong>{{ $t->nome }}</strong> <span class="nota">({{ \App\Models\Tipologia::VERSI[$t->verso] }})</span>:
                        {{ $riga === null ? 'nessuna riga: le sue imputazioni in C2 non entrano nelle raccolte' : ($scelte[$riga] ?? $riga) }}</p>
                    @if (! $approvato && $aperto)
                        <form method="POST" action="{{ route('esercizio.raccolte.righe.imposta', [$anno, $t]) }}" class="modulo-in-riga" data-merlo="righe-raccolta-modulo">
                            @csrf @method('PUT')
                            <label>Riga
                                <select name="riga">
                                    <option value="">Nessuna (togli la riga)</option>
                                    @foreach ($scelte as $chiave => $etichetta) <option value="{{ $chiave }}" @selected($riga === $chiave)>{{ $etichetta }}</option> @endforeach
                                </select>
                            </label>
                            <button type="submit" class="bottone">Salva</button>
                        </form>
                    @endif
                </li>
            @empty
                <li class="vuoto">Nessuna tipologia ha una voce in area C nel {{ $anno }}.</li>
            @endforelse
        </ul>
    </section>
@endsection
