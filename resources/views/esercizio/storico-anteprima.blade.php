@extends('layouts.app')

@section('titolo', 'Esercizio storico '.$anno.': anteprima')

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Esercizio storico {{ $anno }}: anteprima</h1>
    <p class="nota">Dal foglio «{{ \App\Esercizio\ImportStorico::FOGLIO }}» si sono letti gli importi delle voci numerate e i saldi finali. Finché non confermi non si salva nulla; dopo la conferma l’esercizio è bloccato e lo modifica solo un Amministratore, con motivazione.</p>

    <form method="POST" action="{{ route('esercizio.storico.conferma', [$anno, $token]) }}" class="modulo" data-merlo="storico-anteprima">
        @csrf

        <h2>Righe da abbinare a mano</h2>
        @forelse ($anteprima['nonRiconosciute'] as $riga)
            <label>Riga {{ $riga['riga'] }} del foglio: «{{ $riga['etichetta'] }}» · {{ $euro($riga['importo']) }} €
                <select name="abbinamenti[{{ $riga['riga'] }}]">
                    <option value="">Ignora la riga</option>
                    @foreach ($voci as $chiave => $etichetta)
                        <option value="{{ $chiave }}" @selected(($abbinamenti[$riga['riga']] ?? '') === $chiave)>{{ $etichetta }}</option>
                    @endforeach
                </select>
            </label>
        @empty
            <p class="vuoto">Tutte le righe con un importo sono state riconosciute.</p>
        @endforelse

        <h2>Totali e avanzi ricalcolati dall’app</h2>
        @include('esercizio.storico-totali', ['m' => $m])
        @include('esercizio.storico-differenze', ['differenze' => $differenze])

        <h2>Esempi di classificazione</h2>
        @php($motivi = ['senza_data' => 'senza data', 'fuori_anno' => 'di un altro anno', 'senza_causale' => 'senza «Nuova causale»', 'non_convertita' => 'con una causale che la tabella di conversione non conosce', 'senza_tipologia' => 'la cui causale non ha una tipologia (giroconti e simili)', 'senza_importo' => 'senza importo', 'verso_diverso' => 'con un segno diverso da quello della causale', 'senza_descrizione' => 'senza descrizione'])
        <p data-merlo="storico-esempi">Dalle schede dei conti ({{ implode(', ', $anteprima['schede']) ?: 'nessuna scheda riconosciuta' }}) si ricavano <strong>{{ count($anteprima['esempi']) }}</strong> esempi di classificazione, ognuno con descrizione, causale dell’estratto, segno, importo e classificazione convertita, senza nomi né email. Non sono movimenti e non entrano in nessun rendiconto: servono allo storico delle proposte.
            @foreach ($anteprima['scartate'] as $motivo => $numero) <br>{{ $numero }} {{ $numero === 1 ? 'riga scartata' : 'righe scartate' }}: {{ $motivi[$motivo] ?? $motivo }}. @endforeach
        </p>

        <h2>Saldi finali</h2>
        <label>Cassa al 31/12/{{ $anno }} (€) <input type="text" name="saldo_cassa" inputmode="decimal" maxlength="30" value="{{ $saldoCassa }}" required></label>
        <label>Depositi bancari e postali al 31/12/{{ $anno }} (€) <input type="text" name="saldo_depositi" inputmode="decimal" maxlength="30" value="{{ $saldoDepositi }}" required></label>
        @error('saldo_cassa') <p class="errore" role="alert">{{ $message }}</p> @enderror
        @error('saldo_depositi') <p class="errore" role="alert">{{ $message }}</p> @enderror

        <h2>Costi e proventi figurativi</h2>
        <p class="nota">Il foglio non li ha: scrivili a mano (€), zero se non ce ne sono.</p>
        <fieldset>
            @foreach (['costi' => 'Costi figurativi', 'proventi' => 'Proventi figurativi'] as $tipo => $titolo)
                @foreach (\App\Esercizio\SchemaModelloD::figurativi() as $area => $etichetta)
                    <label>{{ $titolo }} · {{ $etichetta }} <input type="text" name="figurativi[{{ $tipo }}][{{ $area }}]" inputmode="decimal" maxlength="30" value="{{ $figurativi[$tipo][$area] ?? '0' }}"></label>
                    @error("figurativi.$tipo.$area") <p class="errore" role="alert">{{ $message }}</p> @enderror
                @endforeach
            @endforeach
        </fieldset>
        @error('abbinamenti') <p class="errore" role="alert">{{ $message }}</p> @enderror
        @error('esercizio') <p class="errore" role="alert">{{ $message }}</p> @enderror

        <button type="submit" name="azione" value="ricalcola" class="bottone-piccolo">Ricalcola con gli abbinamenti</button>
        <button type="submit" name="azione" value="conferma" class="bottone">Conferma e blocca l’esercizio {{ $anno }}</button>
    </form>
@endsection
