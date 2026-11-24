@extends('layouts.app')

@section('titolo', $conto->exists ? 'Modifica conto' : 'Nuovo conto')

@section('contenuto')
    <h1>{{ $conto->exists ? 'Modifica '.$conto->nome : 'Nuovo conto' }}</h1>

    <form method="POST" action="{{ $conto->exists ? route('conti.salva', $conto) : route('conti.crea') }}" class="modulo">
        @csrf
        @if ($conto->exists) @method('PUT') @endif
        <label>Tipo
            <select name="tipo" @disabled($conto->exists) required>
                @foreach (\App\Models\Conto::TIPI as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('tipo', $conto->tipo) === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </label>
        <label>Nome <input type="text" name="nome" value="{{ old('nome', $conto->nome) }}" required maxlength="100"></label>
        <label>Formato di import
            <select name="formato_import" required>
                @foreach (\App\Models\Conto::FORMATI_IMPORT as $tipo => $formati)
                    <optgroup label="{{ \App\Models\Conto::TIPI[$tipo] }}">
                        @foreach ($formati as $valore => $etichetta)
                            <option value="{{ $valore }}" @selected(old('formato_import', $conto->formato_import) === $valore)>{{ $etichetta }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
            <span class="nota">Deve essere uno dei formati del tipo scelto.</span>
        </label>
        <label>Riga del Modello D
            <select name="riga_modello_d">
                <option value="">Secondo il tipo (cassa per la cassa, depositi per gli altri)</option>
                @foreach (\App\Models\Conto::RIGHE_MODELLO_D as $valore => $etichetta)
                    <option value="{{ $valore }}" @selected(old('riga_modello_d', $conto->exists ? $conto->riga_modello_d : '') === $valore)>{{ $etichetta }}</option>
                @endforeach
            </select>
        </label>
        <label>Data di apertura <input type="date" name="aperto_il" value="{{ old('aperto_il', $conto->aperto_il?->toDateString()) }}" required></label>
        <label>Saldo di partenza (€) <input type="text" name="saldo_iniziale" inputmode="decimal" value="{{ old('saldo_iniziale', $conto->exists ? \App\Euro::formatta($conto->saldo_iniziale) : '0,00') }}" required></label>
        <p class="nota">La chiusura si fa dall’elenco dei conti.</p>

        <button type="submit" class="bottone">Salva</button>
        <a href="{{ route('conti') }}" class="link">Annulla</a>
    </form>
@endsection
