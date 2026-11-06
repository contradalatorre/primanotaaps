<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #111; }
        h1 { font-size: 15pt; margin: 0 0 2mm; }
        table { border-collapse: collapse; width: 100%; margin-top: 5mm; }
        th, td { border: 0.3mm solid #111; padding: 1.5mm 2mm; text-align: left; vertical-align: top; }
        th { background-color: #ffd900; }
        .nota { font-size: 8pt; color: #444; }
    </style>
</head>
<body>
    <h1>Elenco degli autorizzati</h1>
    <p>Contrada La Torre APS · prima nota · aggiornato al {{ $data->copy()->locale('it')->translatedFormat('j F Y') }}</p>
    <p class="nota">Persone che possono accedere ai dati dell’app con la loro assegnazione in corso (ruolo, conti su cui possono scrivere, scadenza). Le assegnazioni scadute non sono elencate.</p>

    <table>
        <thead>
            <tr><th>Persona</th><th>Ruolo</th><th>Conti</th><th>Scadenza</th><th>Nomina firmata il</th></tr>
        </thead>
        <tbody>
            @forelse ($assegnazioni as $a)
                <tr>
                    <td>{{ $a->user->name }}<br><span class="nota">{{ $a->user->email }}{{ $a->user->esterno ? ' · esterno, sola lettura' : '' }}</span></td>
                    <td>{{ $a->ruolo->nome }}</td>
                    <td>{{ $a->ambito() }}</td>
                    <td>{{ $a->scade_il?->format('d/m/Y') ?? 'nessuna' }}</td>
                    <td>{{ $a->nomina_firmata_il?->format('d/m/Y') ?? '–' }}</td>
                </tr>
            @empty
                <tr><td colspan="5">Nessuna assegnazione in corso.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
