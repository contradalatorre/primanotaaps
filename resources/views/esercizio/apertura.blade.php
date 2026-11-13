@extends('layouts.app')

@section('titolo', 'Apertura dell’esercizio '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Apertura dell’esercizio {{ $anno }}: saldi al 1/1/{{ $anno }}</h1>
    <p class="nota">I saldi dei conti aperti il 1/1/{{ $anno }}, da inserire una sola volta. Dagli esercizi dopo l’apertura è la chiusura dell’anno prima; un conto aperto in seguito si crea con il suo saldo di partenza.</p>

    @if ($chiusura)
        <p data-merlo="apertura-chiusura">Chiusura {{ $anno - 1 }} nel foglio: cassa {{ $euro($chiusura['cassa']) }} €, depositi {{ $euro($chiusura['depositi']) }} €. La somma dei saldi iniziali di ogni riga deve coincidere.</p>
    @else
        <p class="avviso" role="status" data-merlo="apertura-senza-storico">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 3l10 18H2zM12 10v5M12 18h.01"/></svg>
            <span>Esercizio storico {{ $anno - 1 }} non caricato: nessun controllo con la chiusura dell’anno prima.
                @can('gestire_esercizio') <a href="{{ route('esercizio.storico', $anno - 1) }}" class="link">Caricalo dal foglio approvato</a> @endcan
            </span>
        </p>
    @endif

    @if ($fatta)
        <p class="nota" data-merlo="apertura-fatta">I saldi di apertura sono già inseriti: non si inseriscono due volte.</p>
        <div class="tabella-scorrevole">
            <table class="tabella">
                <thead><tr><th scope="col">Conto</th><th scope="col">Riga del Modello D</th><th scope="col" class="text-right">Saldo al 1/1/{{ $anno }} (€)</th></tr></thead>
                <tbody>
                    @foreach ($conti as $conto)
                        <tr><td>{{ $conto->nome }}</td><td>{{ \App\Models\Conto::RIGHE_MODELLO_D[$conto->riga_modello_d] ?? $conto->riga_modello_d }}</td><td class="text-right">{{ $euro($conto->saldo_iniziale) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif ($conti->isEmpty())
        <p class="vuoto">Nessun conto aperto il 1/1/{{ $anno }}: <a href="{{ route('conti') }}" class="link">crea i conti</a> e torna qui.</p>
    @else
        <form method="POST" action="{{ route('esercizio.saldi.inserisci', $anno) }}" class="modulo" data-merlo="apertura-modulo">
            @csrf
            @foreach ($conti as $conto)
                <label>{{ $conto->nome }} ({{ \App\Models\Conto::RIGHE_MODELLO_D[$conto->riga_modello_d] ?? $conto->riga_modello_d }}) · saldo al 1/1/{{ $anno }} (€)
                    <input type="text" name="saldi[{{ $conto->id }}]" inputmode="decimal" maxlength="30" value="{{ old('saldi.'.$conto->id, $euro($precompilati[$conto->id] ?? $conto->saldo_iniziale)) }}" required>
                    @php($r = $riscontri[$conto->id])
                    @if ($r['estratto']) <span class="nota">Estratto: saldo iniziale {{ $euro($r['estratto']['saldo']) }} € (dal {{ $r['estratto']['dal'] }})</span> @endif
                    @if ($r['conta'] !== null) <span class="nota">Conta di cassa al 01/01/{{ $anno }}: {{ $euro($r['conta']) }} €</span> @endif
                </label>
                @error('saldi.'.$conto->id) <p class="errore" role="alert">{{ $message }}</p> @enderror
            @endforeach
            @error('saldi') <p class="errore" role="alert">{{ $message }}</p> @enderror
            @error('apertura') <p class="errore" role="alert">{{ $message }}</p> @enderror
            <button type="submit" class="bottone">Inserisci i saldi (una sola volta)</button>
        </form>
    @endif
@endsection
