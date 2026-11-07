@extends('layouts.app')

@section('titolo', 'Movimenti')

@section('contenuto')
    <h1>Movimenti{{ $conto ? ' · '.$conto->nome : '' }}</h1>

    <form method="GET" action="{{ route('movimenti') }}" class="modulo-in-riga">
        <label>Conto
            <select name="conto" onchange="this.form.submit()">
                <option value="">Tutti i conti</option>
                @foreach ($conti as $c) <option value="{{ $c->id }}" @selected($conto?->id === $c->id)>{{ $c->nome }}</option> @endforeach
            </select>
        </label>
        <label class="spunta"><input type="checkbox" name="senza_giustificativo" value="1" @checked($senzaGiustificativo) onchange="this.form.submit()"> <span>Solo spese senza giustificativo</span></label>
        <noscript><button type="submit" class="bottone">Filtra</button></noscript>
    </form>

    {{-- Scrivere è limitato dall'ambito dell'assegnazione: il pulsante c'è solo sulle casse aperte dove si può. --}}
    @php($scrivibili = $conti->filter(fn ($c) => $c->tipo === 'cassa' && ! $c->chiuso() && auth()->user()->can('inserire_movimenti_cassa', $c->id)))
    @if ($scrivibili->isNotEmpty())
        <p class="azioni">
            @foreach ($scrivibili as $c)
                <a href="{{ route('movimenti.nuovo', $c) }}" class="bottone">Nuovo movimento · {{ $c->nome }}</a>
            @endforeach
        </p>
    @endif

    <ul class="schede">
        @forelse ($movimenti as $movimento)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong class="importo">{{ $movimento->verso === 'uscita' ? '−' : '+' }}{{ \App\Euro::formatta($movimento->importo) }} €</strong>
                    <span class="etichetta">{{ \App\Models\Movimento::VERSI[$movimento->verso] }}</span>
                    <span class="etichetta">{{ ['da_classificare' => 'Da classificare', 'proposto' => 'Proposto', 'confermato' => 'Confermato'][$movimento->stato] }}</span>
                </div>
                <p>{{ $movimento->descrizione }}</p>
                <p class="nota">
                    {{ $movimento->data->format('d/m/Y') }} · {{ $movimento->conto->nome }}
                    @if ($movimento->valuta_originale) · valuta originale: {{ $movimento->valuta_originale }} @endif
                    @if ($movimento->causale) · {{ $movimento->causale }} @endif
                    <br>{{ $movimento->manuale ? 'Inserito' : 'Importato' }} da {{ $movimento->autore->name }} il {{ $movimento->created_at->format('d/m/Y') }}
                </p>
                @foreach ($movimento->imputazioni as $imputazione)
                    <p class="nota">
                        {{ \App\Euro::formatta($imputazione->importo) }} € · area {{ $imputazione->area->value }}
                        @if ($imputazione->attivita) · {{ $imputazione->attivita->nome }} @endif
                        @if ($imputazione->iniziativa) · {{ $imputazione->iniziativa->nome }} @endif
                        · {{ $imputazione->tipologia?->nome ?? 'tipologia da scegliere' }}
                        @php($voce = $imputazione->etichettaVoce())
                        @if ($voce) · <strong>{{ $voce }}</strong> @endif
                        @foreach ($imputazione->tag as $t) <span class="etichetta">{{ $t->nome }}</span> @endforeach
                    </p>
                @endforeach
                @if ($movimento->tipo_controparte)
                    <p class="nota">Controparte: {{ \App\Models\Movimento::TIPI_CONTROPARTE[$movimento->tipo_controparte] }}</p>
                @endif
                @if ($movimento->stato === 'proposto' && $movimento->proponente)
                    <p class="nota">Proposta da {{ $movimento->proponente->name }}</p>
                @endif
                @foreach ($movimento->giustificativi as $g)
                    <p class="nota">Giustificativo: {{ $g->riferimento }}@if ($g->link) · <a href="{{ $g->link }}" class="link" rel="noopener noreferrer" target="_blank">Apri</a>@endif</p>
                @endforeach
                <div class="azioni">
                    @if ($movimento->classificabileDa(auth()->user()))
                        <a href="{{ route('movimenti.classificazione', $movimento) }}" class="link">{{ $movimento->stato === 'da_classificare' ? 'Classifica' : 'Rivedi la classificazione' }}</a>
                    @endif
                    @if ($movimento->stato === 'proposto')
                        @can('importare_e_confermare')
                            <form method="POST" action="{{ route('movimenti.conferma', $movimento) }}">@csrf <input type="hidden" name="versione" value="{{ \App\Classificazione::versione($movimento) }}"> <button type="submit" class="link">Conferma</button></form>
                        @endcan
                    @endif
                </div>
                <p class="azioni">
                    @if ($movimento->modificabileDa(auth()->user()))
                        <a href="{{ route('movimenti.modifica', $movimento) }}" class="link">Modifica</a>
                        <form method="POST" action="{{ route('movimenti.ritira', $movimento) }}">@csrf <button type="submit" class="link">Ritira</button></form>
                    @endif
                    @can('vedere_storia_movimento')
                        <a href="{{ route('movimenti.storia', $movimento) }}" class="link">Storia</a>
                    @endcan
                </p>
                @if ($movimento->manuale)
                    @can('eliminare_movimento_manuale')
                        <form method="POST" action="{{ route('movimenti.elimina', $movimento) }}" class="modulo-in-riga">
                            @csrf
                            <label>Motivazione dell’eliminazione <input type="text" name="motivazione" required maxlength="1000"></label>
                            <button type="submit" class="bottone">Elimina</button>
                        </form>
                    @endcan
                @endif
            </li>
        @empty
            <li class="vuoto">Nessun movimento.</li>
        @endforelse
    </ul>

    {{ $movimenti->links() }}
@endsection
