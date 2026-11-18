{{-- I controlli di un import del foglio della cassa (#64): saldo finale, saldo iniziale, mese per mese, righe sparite. $foglio viene da App\ImportFoglio::controlli. --}}
@php($euro = fn (?int $c) => $c === null ? '–' : \App\Euro::formatta($c).' €')
<h2>Controlli del foglio</h2>
@if ($foglio['finale']['ok'])
    @if ($foglio['finale']['foglio'] !== null)
        <p class="nota">Il saldo finale del foglio ({{ $euro($foglio['finale']['foglio']) }}) torna con quello dell’app.</p>
    @else
        <p class="avviso" role="status">Il foglio non ha la colonna Saldo: i controlli sul saldo non si possono fare.</p>
    @endif
@else
    <p class="avviso" role="alert">Il saldo finale del foglio ({{ $euro($foglio['finale']['foglio']) }}) non torna con quello dell’app ({{ $euro($foglio['finale']['app']) }}): l’import non si può confermare. Controlla il foglio e ricaricalo.</p>
@endif
@if ($foglio['iniziale'] && ! $foglio['iniziale']['ok'])
    <p class="avviso" role="status">Il saldo iniziale del foglio ({{ $euro($foglio['iniziale']['foglio']) }}) non è quello di apertura del conto ({{ $euro($foglio['iniziale']['apertura']) }}).</p>
@endif

@if ($foglio['mesi'])
    <table class="tabella" data-merlo="controlli-mesi">
        <thead><tr><th>Mese</th><th>Entrate foglio</th><th>Entrate app</th><th>Uscite foglio</th><th>Uscite app</th><th>Saldo foglio</th><th>Saldo app</th><th></th></tr></thead>
        <tbody>
        @foreach ($foglio['mesi'] as $m)
            <tr>
                <td>{{ \App\ImportFoglio::mese($m['mese']) }}</td>
                <td>{{ $euro($m['foglio']['entrate']) }}</td><td>{{ $euro($m['app']['entrate']) }}</td>
                <td>{{ $euro($m['foglio']['uscite']) }}</td><td>{{ $euro($m['app']['uscite']) }}</td>
                <td>{{ $euro($m['foglio']['saldo']) }}</td><td>{{ $euro($m['app']['saldo']) }}</td>
                <td>{{ $m['ok'] ? 'Torna' : 'Non torna' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p class="nota">Un mese che non torna non ferma l’import: diventa un dubbio sull’ultimo movimento del mese.</p>
@endif

@if ($foglio['sparite'])
    <h3>Righe non più nel foglio</h3>
    <p class="nota">L’import le aveva portate e il foglio di adesso non le ha più. Non si cancellano mai: restano nell’app, con un dubbio.</p>
    <ul class="schede">
        @foreach ($foglio['sparite'] as $s)
            <li class="scheda"><strong>{{ $euro($s['importo']) }}</strong> · {{ date('d/m/Y', strtotime($s['data'])) }} · {{ $s['descrizione'] }}</li>
        @endforeach
    </ul>
@endif
