@extends('layouts.app')

@section('titolo', 'Manuale di classificazione')

@section('contenuto')
    <h1>Manuale di classificazione</h1>
    <p class="nota">
        Come si classificano i movimenti e perché. Ogni sezione si cita con il suo numero, «manuale § 9», che non cambia.
        <a href="{{ route('manuale.esporta') }}">Esporta il manuale</a> (file di testo).
    </p>

    @can('gestire_regole')
        <details class="scheda">
            <summary>Nuova sezione</summary>
            <form method="POST" action="{{ route('manuale.crea') }}" class="modulo modulo-stretto">
                @csrf
                <label>Titolo <input type="text" name="titolo" maxlength="255" required></label>
                <label>Testo <textarea name="corpo" rows="6" maxlength="65000" required></textarea></label>
                <p class="nota">Il testo si scrive in Markdown: **grassetto**, elenchi con «-». Il numero della sezione lo dà l’app.</p>
                <button type="submit" class="bottone">Aggiungi</button>
            </form>
        </details>
    @endcan

    <nav aria-label="Indice del manuale">
        <ol class="indice-manuale">
            @foreach ($sezioni as $sezione)
                <li><a href="#sezione-{{ $sezione->codice }}">§ {{ $sezione->codice }} {{ $sezione->titolo }}</a></li>
            @endforeach
            <li><a href="#tipologie">§ tipologie Le tipologie</a></li>
        </ol>
    </nav>

    @foreach ($sezioni as $sezione)
        <section id="sezione-{{ $sezione->codice }}" class="scheda">
            <h2>§ {{ $sezione->codice }} {{ $sezione->titolo }}</h2>
            {!! $sezione->html() !!}
            @can('gestire_regole')
                <details>
                    <summary>Modifica</summary>
                    <form method="POST" action="{{ route('manuale.salva', $sezione) }}" class="modulo modulo-stretto">
                        @csrf @method('PUT')
                        <input type="hidden" name="versione" value="{{ $sezione->versione() }}">
                        <label>Titolo <input type="text" name="titolo" value="{{ $sezione->titolo }}" maxlength="255" required></label>
                        <label>Testo <textarea name="corpo" rows="12" maxlength="65000" required>{{ $sezione->corpo }}</textarea></label>
                        <button type="submit" class="bottone">Salva</button>
                    </form>
                </details>
            @endcan
        </section>
    @endforeach

    <section id="tipologie" class="scheda">
        <h2>§ tipologie Le tipologie</h2>
        <p class="nota">La spiegazione semplice di ogni tipologia si modifica dalla pagina <a href="{{ route('classificazione') }}">Classificazione</a>.</p>
        <dl class="dati">
            @foreach ($tipologie as $tipologia)
                <div id="tipologia-{{ $tipologia->id }}">
                    <dt>{{ $tipologia->nome }} <span class="etichetta">{{ \App\Models\Tipologia::VERSI[$tipologia->verso] }}</span></dt>
                    <dd>{{ $tipologia->spiegazione }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endsection
