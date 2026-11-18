@extends('layouts.app')

@section('titolo', 'Tabella di conversione delle causali')

@section('contenuto')
    <h1>Tabella di conversione delle causali</h1>
    <p class="nota">
        Traduce la «Nuova causale» del foglio della cassa in tipologia, area, attività, iniziativa e tag. L’import la usa per proporre la classificazione.
        Cambiando una riga, la nuova classificazione si riapplica ai movimenti <strong>da classificare</strong> o <strong>proposti dalla tabella</strong>;
        quelli già <strong>confermati</strong> restano come sono e compaiono in fondo, da correggere. Le scelte fatte a mano non si toccano mai.
    </p>
    @if ($chiusa)
        <p class="avviso" role="status" data-merlo="tabella-chiusa">La data di passaggio è passata: la tabella è chiusa insieme all’import e non si modifica più.</p>
    @endif
    @error('tabella') <p class="avviso" role="alert">{{ $message }}</p> @enderror

    @php($puoModificare = ! $chiusa && auth()->user()->can('gestire_regole'))

    <ul class="schede">
        @foreach ($righe as $riga)
            <li class="scheda" id="riga-{{ $riga->id }}">
                <div class="scheda-testa">
                    <strong>{{ $riga->causale }}</strong>
                    <span class="etichetta">{{ \App\Models\ConversioneCausale::VERSI[$riga->verso] }}</span>
                </div>
                @if ($riga->verso === 'giroconto')
                    <p class="nota">Prelievi e versamenti sono giroconti con il conto bancario: non si classificano, si abbinano.</p>
                @elseif (! $puoModificare)
                    <p class="nota">
                        {{ $riga->tipologia?->nome ?? 'Senza tipologia: i movimenti arrivano come dubbio' }}
                        @if ($riga->area) · area {{ $riga->area }} @endif
                        @if ($riga->attivita) · {{ $riga->attivita->nome }} @endif
                        @if ($riga->iniziativa) · {{ $riga->iniziativa->nome }} @endif
                        @if ($riga->tag) · tag {{ $riga->tag->nome }} @endif
                    </p>
                @else
                    <form method="POST" action="{{ route('conversione.salva', $riga) }}" class="modulo-in-riga">
                        @csrf @method('PUT')
                        <label>Tipologia
                            <select name="tipologia_id">
                                <option value="">Nessuna (dubbio)</option>
                                @foreach ($tipologie->where('verso', $riga->verso) as $t) <option value="{{ $t->id }}" @selected($riga->tipologia_id === $t->id)>{{ $t->nome }}</option> @endforeach
                            </select>
                        </label>
                        <label>Area
                            <select name="area">
                                <option value="">Dall’iniziativa</option>
                                @foreach (\App\Area::cases() as $a) <option value="{{ $a->value }}" @selected($riga->area === $a->value)>{{ $a->etichetta() }}</option> @endforeach
                            </select>
                        </label>
                        <label>Attività
                            <select name="attivita_id">
                                <option value="">Nessuna</option>
                                @foreach ($attivita as $a) <option value="{{ $a->id }}" @selected($riga->attivita_id === $a->id)>{{ $a->area->value }} · {{ $a->nome }}</option> @endforeach
                            </select>
                        </label>
                        <label>Iniziativa
                            <select name="iniziativa_id">
                                <option value="">Nessuna</option>
                                @foreach ($iniziative as $i) <option value="{{ $i->id }}" @selected($riga->iniziativa_id === $i->id)>{{ $i->nome }}</option> @endforeach
                            </select>
                        </label>
                        <label>Tag
                            <select name="tag_id">
                                <option value="">Nessuno</option>
                                @foreach ($tag as $t) <option value="{{ $t->id }}" @selected($riga->tag_id === $t->id)>{{ $t->nome }}</option> @endforeach
                            </select>
                        </label>
                        <button type="submit" class="bottone">Salva e riapplica</button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($puoModificare)
        <h2>Aggiungi una causale</h2>
        <p class="nota">Per una causale del foglio che non c’è ancora: i movimenti che la aspettano, ancora da classificare, ricevono la proposta.</p>
        <form method="POST" action="{{ route('conversione.crea') }}" class="modulo">
            @csrf
            <label>Causale, com’è scritta nel foglio <input type="text" name="causale" value="{{ old('causale') }}" maxlength="255" required></label>
            <label>Verso
                <select name="verso" required>
                    @foreach (['entrata' => 'Entrata', 'uscita' => 'Uscita'] as $valore => $etichetta) <option value="{{ $valore }}" @selected(old('verso') === $valore)>{{ $etichetta }}</option> @endforeach
                </select>
            </label>
            <label>Tipologia
                <select name="tipologia_id">
                    <option value="">Nessuna (dubbio)</option>
                    @foreach ($tipologie as $t) <option value="{{ $t->id }}" @selected((string) old('tipologia_id') === (string) $t->id)>{{ $t->nome }} ({{ \App\Models\Tipologia::VERSI[$t->verso] }})</option> @endforeach
                </select>
            </label>
            <label>Area
                <select name="area">
                    <option value="">Dall’iniziativa</option>
                    @foreach (\App\Area::cases() as $a) <option value="{{ $a->value }}" @selected(old('area') === $a->value)>{{ $a->etichetta() }}</option> @endforeach
                </select>
            </label>
            <label>Iniziativa
                <select name="iniziativa_id">
                    <option value="">Nessuna</option>
                    @foreach ($iniziative as $i) <option value="{{ $i->id }}" @selected((string) old('iniziativa_id') === (string) $i->id)>{{ $i->nome }}</option> @endforeach
                </select>
            </label>
            <button type="submit" class="bottone">Aggiungi</button>
        </form>
    @endif

    <h2>Confermati da correggere</h2>
    <p class="nota">Movimenti già confermati con una classificazione diversa da quella che la tabella propone oggi. Non cambiano da soli: si correggono dalla revisione del loro import, con i selezionati.</p>
    @forelse ($daCorreggere as $importId => $movimenti)
        <div class="scheda">
            <p><a href="{{ route('import.revisione', [$importId, 'passo' => 'tutti']) }}" class="link">Import n. {{ $importId }}: {{ $movimenti->count() }} {{ $movimenti->count() === 1 ? 'movimento' : 'movimenti' }}</a></p>
            <ul>
                @foreach ($movimenti as $m)
                    <li>{{ $m->data->format('d/m/Y') }} · {{ $m->descrizione }} · {{ \App\Euro::formatta($m->importo) }} € · <span class="nota">{{ $m->conversioneCausale->causale }}</span>
                        <a href="{{ route('movimenti.classificazione', $m) }}" class="link">Apri</a></li>
                @endforeach
            </ul>
        </div>
    @empty
        <p class="vuoto">Nessuno.</p>
    @endforelse
@endsection
