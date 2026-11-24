@extends('layouts.app')

@section('titolo', 'Stato del sistema')

@section('contenuto')
    <h1>Stato del sistema</h1>
    <p class="nota">La salute dell’app in un colpo d’occhio. Le integrazioni esterne (drive condiviso, posta, Google) sono facoltative: se una non risponde l’app va avanti e lo vedi qui.</p>

    <section aria-label="Backup" id="backup">
        <h2>Backup</h2>
        @if ($ultimoRiuscito)
            <p>
                Ultimo backup riuscito: <strong>{{ $ultimoRiuscito->avviato_il->format('d/m/Y H:i') }}</strong>
                ({{ \App\Models\Backup::TIPI[$ultimoRiuscito->tipo] }}), {{ number_format($ultimoRiuscito->dimensione / 1024, 0, ',', '.') }} KB, sul drive condiviso come
                <code>{{ $ultimoRiuscito->file }}</code>.
            </p>
        @else
            <p class="vuoto">Nessun backup riuscito finora.</p>
        @endif
        @if ($ultimoTentativo && $ultimoTentativo->esito !== 'riuscito')
            <p class="avviso" role="status">
                @if ($ultimoTentativo->esito === 'fallito')
                    L’ultimo tentativo, il {{ $ultimoTentativo->avviato_il->format('d/m/Y H:i') }}, non è riuscito: {{ $ultimoTentativo->errore }}
                @else
                    Un backup avviato il {{ $ultimoTentativo->avviato_il->format('d/m/Y H:i') }} non risulta concluso.
                @endif
            </p>
        @endif
        <p class="nota">Il backup mensile parte da solo il primo del mese. Si tengono gli ultimi 12 mensili e uno per anno, per 10 anni. Contiene dati personali.</p>
        <form method="POST" action="{{ route('sistema.backup') }}">
            @csrf
            <button type="submit" class="bottone">Fai un backup adesso</button>
        </form>
    </section>

    <section aria-label="Conti" id="conti-stato">
        <h2>Import e riconciliazione per conto</h2>
        <div class="tabella-scorrevole">
            <table class="tabella">
                <thead>
                    <tr><th scope="col">Conto</th><th scope="col">Ultimo import</th><th scope="col">Riconciliato fino al</th></tr>
                </thead>
                <tbody>
                    @forelse ($conti as $riga)
                        <tr>
                            <th scope="row">{{ $riga['conto']->nome }}</th>
                            <td>
                                @if ($riga['ultimoImport'])
                                    fino al {{ $riga['ultimoImport']->periodo_al->format('d/m/Y') }}
                                    <span class="nota">(confermato il {{ $riga['ultimoImport']->confermato_il->format('d/m/Y') }})</span>
                                @else
                                    <span class="vuoto">nessun import</span>
                                @endif
                            </td>
                            <td>{{ $riga['riconciliatoFinoAl']?->format('d/m/Y') ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="vuoto">Nessun conto aperto.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section aria-label="Errori aperti" id="errori">
        <h2>Errori aperti <span class="nota">{{ $errori->count() }}</span></h2>
        <ul class="schede">
            @forelse ($errori as $errore)
                <li class="scheda">
                    <p>
                        <strong>{{ $errore->origine }}</strong>
                        @if ($errore->grave) <span class="etichetta">grave</span> @endif
                        <span class="nota">dal {{ $errore->primo_il->format('d/m/Y H:i') }}, ultima volta il {{ $errore->ultimo_il->format('d/m/Y H:i') }}@if ($errore->volte > 1), {{ $errore->volte }} volte @endif</span>
                    </p>
                    <p>{{ $errore->messaggio }}</p>
                    <form method="POST" action="{{ route('sistema.errori.risolvi', $errore) }}">
                        @csrf
                        <button type="submit" class="bottone-piccolo">Segna come risolto</button>
                    </form>
                </li>
            @empty
                <li class="vuoto">Nessun errore aperto.</li>
            @endforelse
        </ul>
    </section>

    <section aria-label="Amministratori" id="amministratori">
        <h2>Amministratori <span class="nota">{{ $amministratori->count() }}</span></h2>
        {{-- Il numero lo vede chi ha accesso alla pagina; i nomi e gli indirizzi solo chi gestisce gli utenti (dati personali). --}}
        @can('gestire_utenti')
            <ul>
                @foreach ($amministratori as $amministratore)
                    <li>{{ $amministratore->name }} <span class="nota">{{ $amministratore->email }}</span></li>
                @endforeach
            </ul>
        @endcan
    </section>
@endsection
