@extends('layouts.app')

@section('titolo', 'Utenti')

@section('contenuto')
    <h1>Utenti e assegnazioni</h1>
    <p class="nota">Una persona può avere più assegnazioni e i loro permessi si sommano. Per entrare con l’account dell’associazione basta che la persona abbia fatto un primo accesso; poi le si assegna un ruolo qui.</p>
    <p class="nota">Solo gli account del Workspace dell’associazione sono utenti a pieno titolo. Un account personale (commercialista, revisore) si aggiunge qui sotto come esterno: sola lettura e scadenza obbligatoria.</p>

    <p><a href="{{ route('utenti.pdf') }}" class="bottone">Esporta l’elenco degli autorizzati (PDF)</a></p>

    <div class="tabella-scorrevole">
        <table class="tabella">
            <thead>
                <tr><th scope="col">Persona</th><th scope="col">Assegnazioni</th><th scope="col">Nuova assegnazione</th></tr>
            </thead>
            <tbody>
                @foreach ($utenti as $utente)
                    <tr>
                        <th scope="row">
                            {{ $utente->name }}
                            @if ($utente->esterno) <span class="etichetta">esterno</span> @endif
                            <br><span class="nota">{{ $utente->email }}</span>
                        </th>
                        <td>
                            @forelse ($utente->assegnazioni as $assegnazione)
                                <form method="POST" action="{{ route('assegnazioni.revoca', $assegnazione) }}" class="riga-assegnazione">
                                    @csrf @method('DELETE')
                                    <span>
                                        <strong>{{ $assegnazione->ruolo->nome }}</strong> · {{ $assegnazione->ambito() }}
                                        @if ($assegnazione->scade_il)
                                            · fino al {{ $assegnazione->scade_il->format('d/m/Y') }}
                                            @if ($assegnazione->scade_il->isPast() && ! $assegnazione->scade_il->isToday()) <span class="etichetta">scaduta</span> @endif
                                        @endif
                                        @if ($assegnazione->nomina_firmata_il) · nomina firmata il {{ $assegnazione->nomina_firmata_il->format('d/m/Y') }} @endif
                                    </span>
                                    <button type="submit" class="link">Revoca</button>
                                </form>
                            @empty
                                <span class="vuoto">Nessuna assegnazione: non può fare niente.</span>
                            @endforelse
                        </td>
                        <td>
                            <form method="POST" action="{{ route('utenti.assegna', $utente) }}" class="modulo-in-riga">
                                @csrf
                                <label>Ruolo
                                    <select name="ruolo_id" required>
                                        @foreach ($ruoli as $ruolo) <option value="{{ $ruolo->id }}">{{ $ruolo->nome }}</option> @endforeach
                                    </select>
                                </label>
                                <label>Scade il @unless ($utente->esterno) (facoltativo) @endunless
                                    <input type="date" name="scade_il" @if ($utente->esterno) required @endif min="{{ today()->toDateString() }}">
                                </label>
                                @if ($utente->esterno)
                                    <label>Nomina firmata il <input type="date" name="nomina_firmata_il"></label>
                                @endif
                                <button type="submit" class="bottone">Assegna</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h2>Aggiungi un account esterno</h2>
    <form method="POST" action="{{ route('utenti.esterno') }}" class="modulo">
        @csrf
        <label>Nome <input type="text" name="name" value="{{ old('name') }}" required></label>
        <label>Indirizzo Google <input type="email" name="email" value="{{ old('email') }}" required></label>
        <label>Ruolo
            <select name="ruolo_id" required>
                @foreach ($ruoli as $ruolo) <option value="{{ $ruolo->id }}" @selected(old('ruolo_id') == $ruolo->id || $ruolo->nome === 'Lettore esterno')>{{ $ruolo->nome }}</option> @endforeach
            </select>
        </label>
        <label>Scade il (obbligatorio) <input type="date" name="scade_il" value="{{ old('scade_il') }}" min="{{ today()->toDateString() }}" required></label>
        <label>Nomina firmata il <input type="date" name="nomina_firmata_il" value="{{ old('nomina_firmata_il') }}"></label>
        <p class="nota">Un esterno resta in sola lettura (vede, scrive note e, se il ruolo lo prevede, consulta il registro) anche se il ruolo scelto concede di più. La nomina scritta resta in archivio dell’associazione: qui si annota la data.</p>
        <button type="submit" class="bottone">Aggiungi esterno</button>
    </form>
@endsection
