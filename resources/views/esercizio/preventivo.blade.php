@extends('layouts.app')

@section('titolo', 'Preventivo '.$anno.' · '.$iniziativa->nome)

@section('contenuto')
    <h1>Preventivo {{ $anno }} · {{ $iniziativa->nome }}</h1>
    <p class="nota">Facoltativo e senza approvazione: le entrate e le uscite che prevedi per l’iniziativa nell’esercizio, una riga per tipologia (il verso è quello della tipologia). Nei conti per iniziativa si confrontano con le imputazioni (solo le confermate, salvo che chieda anche le proposte). Per toglierlo svuota tutte le righe.</p>

    <form method="POST" action="{{ route('esercizio.preventivo.salva', [$anno, $iniziativa]) }}" class="modulo" data-merlo="preventivo-modulo">
        @csrf
        @method('PUT')
        @foreach (old('righe', $righe->all()) as $n => $riga)
            <div class="modulo-in-riga">
                <label>Tipologia
                    <select name="righe[{{ $n }}][tipologia_id]">
                        <option value="">—</option>
                        @foreach ($tipologie as $t)
                            <option value="{{ $t->id }}" @selected((string) ($riga['tipologia_id'] ?? '') === (string) $t->id)>{{ $t->nome }} ({{ $t->verso }})</option>
                        @endforeach
                    </select>
                </label>
                <label>Importo (€) <input type="text" inputmode="decimal" name="righe[{{ $n }}][importo]" value="{{ $riga['importo'] ?? '' }}"></label>
            </div>
        @endforeach
        <button type="submit" class="bottone">Salva il preventivo</button>
    </form>
    <p><a href="{{ route('esercizio.iniziative', $anno) }}" class="link">Torna ai conti per iniziativa {{ $anno }}</a></p>
@endsection
