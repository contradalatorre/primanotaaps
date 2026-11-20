{{-- Il promemoria della segnalazione al RUNTS dopo una secondarietà non rispettata (art. 4 DM 107/2021, #17): scadenza, campo «inviata il / protocollo», bozza del testo. Vuole $versione. --}}
<section class="da-vedere" aria-label="Segnalazione al RUNTS" data-merlo="segnalazione-runts">
    <h2>Segnalazione al RUNTS · esercizio {{ $versione->esercizio }}</h2>
    @if ($versione->segnalazione_inviata_il)
        <p>Segnalazione inviata il {{ $versione->segnalazione_inviata_il->format('d/m/Y') }}@if ($versione->segnalazione_protocollo), protocollo {{ $versione->segnalazione_protocollo }}@endif.</p>
    @else
        <p>La secondarietà dell’esercizio {{ $versione->esercizio }} non è rispettata: la segnalazione all’ufficio del RUNTS va fatta <strong>entro il {{ $versione->scadenzaSegnalazione()->format('d/m/Y') }}</strong> (30 giorni dall’approvazione). Il promemoria resta finché non registri l’invio.</p>
    @endif
    <details>
        <summary>Bozza del testo</summary>
        <pre class="testo-nota">{{ \App\Esercizio\Segnalazione::bozza($versione) }}</pre>
    </details>
    @can('gestire_esercizio')
        <form method="POST" action="{{ route('esercizio.segnalazione', $versione->esercizio) }}" class="modulo modulo-stretto">
            @csrf
            <label>Segnalazione inviata il <input type="date" name="data" min="{{ $versione->approvata_il->toDateString() }}" max="{{ today()->toDateString() }}" value="{{ old('data', $versione->segnalazione_inviata_il?->toDateString()) }}" required></label>
            <label>Protocollo <input type="text" name="protocollo" maxlength="100" value="{{ old('protocollo', $versione->segnalazione_protocollo) }}"></label>
            <button type="submit" class="bottone">Registra l’invio</button>
        </form>
    @endcan
</section>
