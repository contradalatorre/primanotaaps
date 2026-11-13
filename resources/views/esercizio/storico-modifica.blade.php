@extends('layouts.app')

@section('titolo', 'Modifica dell’esercizio storico '.$anno)

@section('contenuto')
    @php($euro = fn (int $c) => \App\Euro::formatta($c))
    <h1>Modifica dell’esercizio storico {{ $anno }}</h1>
    <p class="nota">L’esercizio è bloccato: lo modifica solo un Amministratore, con motivazione, e la modifica va nel registro con il valore di prima e di dopo. Totali e avanzi li ricalcola l’app. <a href="{{ route('esercizio.storico', $anno) }}" class="link">Torna all’esercizio</a></p>

    <form method="POST" action="{{ route('esercizio.storico.aggiorna', $anno) }}" class="modulo" data-merlo="storico-modifica">
        @csrf
        @method('PUT')
        <label>Motivazione (obbligatoria) <textarea name="motivazione" rows="3" maxlength="1000" required>{{ old('motivazione') }}</textarea></label>
        @error('motivazione') <p class="errore" role="alert">{{ $message }}</p> @enderror

        <h2>Importi delle voci (€)</h2>
        @foreach ($voci as $chiave => $etichetta)
            <label>{{ $etichetta }} <input type="text" name="importi[{{ $chiave }}]" inputmode="decimal" maxlength="30" value="{{ old("importi.$chiave", $euro($storico->importi[$chiave] ?? 0)) }}"></label>
            @error("importi.$chiave") <p class="errore" role="alert">{{ $message }}</p> @enderror
        @endforeach

        <h2>Saldi finali (€)</h2>
        <label>Cassa <input type="text" name="saldo_cassa" inputmode="decimal" maxlength="30" value="{{ old('saldo_cassa', $euro($storico->saldo_cassa)) }}" required></label>
        <label>Depositi bancari e postali <input type="text" name="saldo_depositi" inputmode="decimal" maxlength="30" value="{{ old('saldo_depositi', $euro($storico->saldo_depositi)) }}" required></label>
        @error('saldo_cassa') <p class="errore" role="alert">{{ $message }}</p> @enderror
        @error('saldo_depositi') <p class="errore" role="alert">{{ $message }}</p> @enderror

        <h2>Costi e proventi figurativi (€)</h2>
        <fieldset>
            @foreach (['costi' => 'Costi figurativi', 'proventi' => 'Proventi figurativi'] as $tipo => $titolo)
                @foreach (\App\Esercizio\SchemaModelloD::figurativi() as $area => $etichetta)
                    <label>{{ $titolo }} · {{ $etichetta }} <input type="text" name="figurativi[{{ $tipo }}][{{ $area }}]" inputmode="decimal" maxlength="30" value="{{ old("figurativi.$tipo.$area", $euro($storico->figurativi[$tipo][$area] ?? 0)) }}"></label>
                    @error("figurativi.$tipo.$area") <p class="errore" role="alert">{{ $message }}</p> @enderror
                @endforeach
            @endforeach
        </fieldset>

        <button type="submit" class="bottone">Salva la modifica</button>
    </form>
@endsection
