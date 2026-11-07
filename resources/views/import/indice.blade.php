@extends('layouts.app')

@section('titolo', 'Import estratto · '.$conto->nome)

@section('contenuto')
    <h1>Import dell’estratto · {{ $conto->nome }}</h1>
    <p class="nota">
        @if ($riconciliatoFinoAl)
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
            <label>Estratto conto (CSV di Nowbanking)
                <input type="file" name="estratto" accept=".csv,.txt,text/csv,text/plain" required>
                <span class="nota">Si legge il file così come lo scarichi, al massimo 2 MB. Prima di salvare vedi un’anteprima.</span>
            </label>
            <button type="submit" class="bottone">Carica e mostra l’anteprima</button>
        </form>
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
