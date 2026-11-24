<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>{{ $titolo }}</title>
    <style>
        body { font-family: dejavusans, sans-serif; font-size: 8pt; color: #111; }
        h1 { font-size: 14pt; margin: 0 0 3mm; }
        h2 { font-size: 10pt; margin: 6mm 0 1mm; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 0.2mm solid #888; padding: 1mm 1.5mm; text-align: left; vertical-align: top; }
        th { background-color: #ffd900; }
        .importo { text-align: right; white-space: nowrap; }
        .grassetto td { font-weight: bold; }
    </style>
</head>
<body>
    {{-- Il PDF di deposito (#60) ha l'indice in prima pagina e un documento dopo l'altro; il PDF di un prospetto ha un solo documento e nessun indice. --}}
    @php($indicizzato = $documenti !== null)
    @if ($indicizzato)
        <tocpagebreak toc-preHTML="&lt;h1&gt;Indice&lt;/h1&gt;" links="1" />
    @endif
    @foreach ($documenti ?? [['titolo' => $titolo, 'sezioni' => $sezioni]] as $documento)
        {{-- Doppio escape: mPDF decodifica `content` e lo riscrive come HTML nell'indice, quindi un `<a>` o un `<img>` nel nome di un'iniziativa lì diventerebbe vivo (#90). --}}
        @if ($indicizzato)<tocentry content="{{ e($documento['titolo']) }}" />@endif
        <h1>{{ $documento['titolo'] }}</h1>
        @foreach ($documento['sezioni'] as $sezione)
            <h2>{{ $sezione['titolo'] }}</h2>
            <table>
                @if ($sezione['colonne'] !== [])
                    <thead><tr>@foreach ($sezione['colonne'] as $i => $colonna)<th @if ($i > 0) class="importo" @endif>{{ $colonna }}</th>@endforeach</tr></thead>
                @endif
                <tbody>
                    @foreach ($sezione['righe'] as $riga)
                        <tr @if ($riga['grassetto']) class="grassetto" @endif>
                            @foreach ($riga['celle'] as $i => $cella)
                                @if ($cella instanceof \App\Esercizio\Importo)
                                    <td class="importo">{{ \App\Euro::formatta($cella->centesimi) }}</td>
                                @else
                                    <td @if (count($riga['celle']) === 1 && count($sezione['colonne']) > 1) colspan="{{ count($sezione['colonne']) }}" @endif>{{ $cella }}</td>
                                @endif
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endforeach
    @endforeach
</body>
</html>
