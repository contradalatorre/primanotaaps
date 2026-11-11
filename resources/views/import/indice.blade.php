@extends('layouts.app')

@section('titolo', 'Import estratto · '.$conto->nome)

@section('contenuto')
    <h1>Import dell’estratto · {{ $conto->nome }}</h1>
    <p class="nota">
        @if ($saldiDichiarati !== null)
            Il report di Satispay non ha saldi: il conto si riconcilia con il riepilogo mensile e con il saldo che dichiari qui sotto.
            @if ($riconciliatoFinoAl) Riconciliato fino al {{ $riconciliatoFinoAl->format('d/m/Y') }}, l’ultimo saldo dichiarato che coincide con l’app. @endif
        @elseif ($riconciliatoFinoAl)
            Il conto è riconciliato fino al {{ $riconciliatoFinoAl->format('d/m/Y') }}.
        @else
            Il conto non è riconciliato: nessun estratto confermato concorda con il saldo dell’app.
        @endif
    </p>

    @if ($conto->chiuso())
        <p class="nota">Il conto è chiuso: non si importano nuovi movimenti.</p>
    @else
        <form method="POST" action="{{ route('import.carica', $conto) }}" enctype="multipart/form-data" class="modulo">
            @csrf
            <label>{{ $saldiDichiarati !== null ? 'Report transazioni di Satispay (CSV)' : 'Estratto conto (CSV di Nowbanking)' }}
                <input type="file" name="estratto" accept=".csv,.txt,text/csv,text/plain" required>
                <span class="nota">Si legge il file così come lo scarichi, al massimo 2 MB. Prima di salvare vedi un’anteprima.</span>
            </label>
            <button type="submit" class="bottone">Carica e mostra l’anteprima</button>
        </form>
        @if (app(\App\Onboarding\Prova::class)->attiva())
            {{-- Solo in prova (#71): al posto del file scaricato dalla banca, l'estratto inventato dello scenario, che passa dallo stesso caricamento. --}}
            <form method="POST" action="{{ route('prova.estratto', $conto) }}" class="modulo-in-riga">
                @csrf
                <button type="submit" class="bottone" data-merlo="prova-estratto">Usa l’estratto di prova</button>
                <span class="nota">Movimenti inventati, nel formato vero di Crédit Agricole.</span>
            </form>
        @endif
    @endif

    @if ($saldiDichiarati !== null)
        <h2>Riepilogo mensile</h2>
        <form method="POST" action="{{ route('import.riepilogo', $conto) }}" enctype="multipart/form-data" class="modulo">
            @csrf
            <label>Riepilogo mensile di Satispay (CSV)
                <input type="file" name="riepilogo" accept=".csv,.txt,text/csv,text/plain" required>
                <span class="nota">File di controllo: non entra nulla, si confronta con i movimenti che l’app ha già.</span>
            </label>
            <button type="submit" class="bottone">Confronta con l’app</button>
        </form>
        @foreach (session('riepilogo', []) as $confronto)
            <div class="scheda">
                <h3>{{ $confronto['mese'] }}</h3>
                <ul>
                    @foreach ($confronto['voci'] as $voce)
                        @php $formatta = fn (int $v) => ($voce['numero'] ?? false) ? $v : \App\Euro::formatta($v).' €'; @endphp
                        <li>{{ $voce['nome'] }}: file {{ $formatta($voce['file']) }}, app {{ $formatta($voce['app']) }} ·
                            @if ($voce['file'] === $voce['app']) <strong>coincide</strong> @else <strong>non coincide</strong> (differenza {{ $formatta($voce['app'] - $voce['file']) }}) @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach

        <h2>Saldo dichiarato</h2>
        <p class="nota">Saldo dell’app oggi: {{ \App\Euro::formatta($conto->saldoAl(today()->toDateString())) }} € (incassi − commissioni − versamenti). Scrivi quello che legge Satispay a una data: se è diverso, la differenza resta da spiegare.</p>
        <form method="POST" action="{{ route('import.saldo', $conto) }}" class="modulo">
            @csrf
            <label>Data <input type="date" name="data" value="{{ old('data', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required></label>
            <label>Saldo dichiarato (€) <input type="text" name="importo" inputmode="decimal" autocomplete="off" value="{{ old('importo') }}" required></label>
            <label>Spiegazione della differenza (se c’è) <textarea name="spiegazione" maxlength="1000">{{ old('spiegazione') }}</textarea></label>
            <button type="submit" class="bottone">Registra il saldo dichiarato</button>
        </form>
        <ul class="schede">
            @foreach ($saldiDichiarati as $saldo)
                @php $differenza = $saldo->differenza($conto); @endphp
                <li class="scheda">
                    <div class="scheda-testa">
                        <strong>{{ $saldo->data->format('d/m/Y') }}: {{ \App\Euro::formatta($saldo->importo) }} €</strong>
                        <span class="etichetta">{{ $differenza === 0 ? 'Coincide con l’app' : ($saldo->spiegazione ? 'Differenza spiegata' : 'Differenza da spiegare') }}</span>
                    </div>
                    @if ($differenza !== 0)
                        <p class="nota">L’app ha {{ \App\Euro::formatta(abs($differenza)) }} € {{ $differenza > 0 ? 'in più' : 'in meno' }}.</p>
                    @endif
                    @if ($saldo->spiegazione) <p>{{ $saldo->spiegazione }}</p> @endif
                    <p class="nota">Dichiarato da {{ $saldo->utente->name }}</p>
                </li>
            @endforeach
        </ul>
    @endif

    <h2>Import precedenti</h2>
    <ul class="schede">
        @forelse ($importazioni as $import)
            <li class="scheda">
                <div class="scheda-testa">
                    <a href="{{ route('import.mostra', $import) }}" class="link">Dal {{ $import->periodo_dal->format('d/m/Y') }} al {{ $import->periodo_al->format('d/m/Y') }}</a>
                    <span class="etichetta">{{ \App\Models\ImportEstratto::STATI[$import->stato] }}</span>
                </div>
                <p class="nota">Caricato da {{ $import->utente->name }} il {{ $import->created_at->format('d/m/Y') }} · {{ $import->righe }} righe</p>
            </li>
        @empty
            <li class="vuoto">Nessun import.</li>
        @endforelse
    </ul>
@endsection
