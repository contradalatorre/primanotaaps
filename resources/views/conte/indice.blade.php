@extends('layouts.app')

@section('titolo', 'Conta di cassa')

@section('contenuto')
    <h1>Conta di cassa · {{ $conto->nome }}</h1>
    <p class="nota">Il saldo dell’app oggi è <strong>{{ \App\Euro::formatta($saldo) }} €</strong>. L’ultima conta senza differenze è la data fino a cui la cassa è riconciliata.</p>

    @if ($conto->chiuso())
        <p class="vuoto">La cassa è chiusa: non si contano più i contanti.</p>
    @else
        @can('importare_e_confermare')
            @can('inserire_movimenti_cassa', $conto->id)
                <form method="POST" action="{{ route('conti.conte.registra', $conto) }}" class="modulo modulo-stretto" data-merlo="conta-modulo">
                    @csrf
                    <label>Contanti contati (€) <input type="text" name="importo" inputmode="decimal" autocomplete="off" placeholder="120,50" value="{{ old('importo') }}" required></label>
                    <label>Data della conta <input type="date" name="data" value="{{ old('data', today()->toDateString()) }}" min="{{ $conto->aperto_il->toDateString() }}" max="{{ today()->toDateString() }}" required></label>
                    <label>Contata insieme a (facoltativo)
                        <select name="contata_con">
                            <option value="">Nessuno</option>
                            @foreach ($persone as $persona) <option value="{{ $persona->id }}" @selected((int) old('contata_con') === $persona->id)>{{ $persona->name }}</option> @endforeach
                        </select>
                    </label>
                    <fieldset>
                        <legend>Se i contanti non sono il saldo dell’app</legend>
                        <label class="spunta"><input type="checkbox" name="correggi" value="1" @checked(old('correggi'))> <span>Correggi con un movimento (la cassa torna a quadrare)</span></label>
                        <label>Oppure spiega la differenza con una nota <textarea name="spiegazione" rows="3" maxlength="2000">{{ old('spiegazione') }}</textarea></label>
                        <p class="nota">Con una nota sola la differenza resta: la cassa non risulta riconciliata finché non la correggi.</p>
                    </fieldset>
                    <button type="submit" class="bottone">Registra la conta</button>
                </form>
            @endcan
        @endcan
    @endif

    <h2>Conte registrate</h2>
    <ul class="schede" data-merlo="conte-elenco">
        @forelse ($conte as $conta)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $conta->data->format('d/m/Y') }}</strong>
                    <span class="etichetta">Al momento della conta: {{ $conta->differenza === 0 ? 'senza differenze' : ($conta->correzione_id ? 'differenza corretta' : 'con differenza') }}</span>
                    <span class="etichetta">Oggi: {{ $conta->quadra() ? 'riconciliata' : 'non riconciliata' }}</span>
                </div>
                <dl class="dati">
                    <div><dt>Contati</dt><dd>{{ \App\Euro::formatta($conta->importo_contato) }} €</dd></div>
                    <div><dt>Saldo dell’app</dt><dd>{{ \App\Euro::formatta($conta->saldo_app) }} €</dd></div>
                    <div><dt>Differenza</dt><dd>{{ $conta->differenza === 0 ? '0,00' : ($conta->differenza > 0 ? '+' : '−').\App\Euro::formatta(abs($conta->differenza)) }} €</dd></div>
                    <div><dt>Contata da</dt><dd>{{ $conta->contataDa->name }}{{ $conta->contataCon ? ' e '.$conta->contataCon->name : '' }}</dd></div>
                </dl>
                @if ($conta->correzione_id)
                    <p class="nota">Corretta con il movimento n. {{ $conta->correzione_id }} (<a href="{{ route('movimenti.storia', $conta->correzione_id) }}" class="link">storia</a>).</p>
                @endif
                @if ($conta->spiegazione) <p>{{ $conta->spiegazione }}</p> @endif
            </li>
        @empty
            <li class="vuoto">Nessuna conta: la prima si registra qui sopra.</li>
        @endforelse
    </ul>
    <p><a href="{{ route('conti') }}" class="link">Torna ai conti</a></p>
@endsection
