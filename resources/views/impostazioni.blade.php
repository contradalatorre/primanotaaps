@extends('layouts.app')

@section('titolo', 'Impostazioni dell’associazione')

@section('contenuto')
    <h1>Impostazioni dell’associazione</h1>
    <p class="nota">Valgono da un esercizio in poi: una modifica «dal {{ $anno }}» non cambia il Modello D degli esercizi precedenti. Compaiono nell’intestazione dei prospetti.</p>

    <form method="POST" action="{{ route('impostazioni.salva') }}" class="modulo modulo-stretto" data-merlo="impostazioni-modulo">
        @csrf
        @method('PUT')
        @php($attuale = $righe->first(fn ($r) => $r->dal_esercizio <= $anno))
        <label>Valide dall’esercizio <input type="number" name="dal_esercizio" min="2020" max="2100" value="{{ old('dal_esercizio', $anno) }}" required></label>
        <label>Denominazione <input type="text" name="denominazione" maxlength="255" value="{{ old('denominazione', $attuale?->denominazione) }}" required></label>
        <label>Codice fiscale <input type="text" name="codice_fiscale" maxlength="16" value="{{ old('codice_fiscale', $attuale?->codice_fiscale) }}"></label>
        <label>Numero RUNTS <input type="text" name="numero_runts" maxlength="255" value="{{ old('numero_runts', $attuale?->numero_runts) }}"></label>
        <label class="spunta"><input type="checkbox" name="affiliazione_rete" value="1" @checked(old('affiliazione_rete', $attuale?->affiliazione_rete))> <span>Affiliata a una rete nazionale con finalità assistenziali riconosciute (art. 85 c. 4 CTS)</span></label>
        <button type="submit" class="bottone">Salva</button>
    </form>

    <h2>Impostazioni in vigore</h2>
    <ul class="schede">
        @foreach ($righe as $riga)
            <li class="scheda">
                <strong>Dal {{ $riga->dal_esercizio }}</strong>
                <dl class="dati">
                    <div><dt>Denominazione</dt><dd>{{ $riga->denominazione }}</dd></div>
                    <div><dt>Codice fiscale</dt><dd>{{ $riga->codice_fiscale ?? 'da inserire' }}</dd></div>
                    <div><dt>Numero RUNTS</dt><dd>{{ $riga->numero_runts ?? 'da inserire' }}</dd></div>
                    <div><dt>Affiliazione a una rete nazionale</dt><dd>{{ $riga->affiliazione_rete ? 'sì' : 'no' }}</dd></div>
                </dl>
            </li>
        @endforeach
    </ul>
@endsection
