@extends('layouts.app')

@section('titolo', 'Conti')

@section('contenuto')
    <h1>Conti</h1>
    <p class="nota">I luoghi in cui l’associazione tiene denaro. Gli importi sono sempre in euro.</p>
    @can('gestire_regole')
        <p><a href="{{ route('conti.nuovo') }}" class="bottone">Nuovo conto</a></p>
    @endcan

    <ul class="schede" data-merlo="conti-elenco">
        @forelse ($conti as $conto)
            <li class="scheda">
                <div class="scheda-testa">
                    <strong>{{ $conto->nome }}</strong>
                    <span class="etichetta">{{ $conto->etichettaTipo() }}</span>
                    @if ($conto->chiuso()) <span class="etichetta">chiuso</span> @endif
                </div>
                <dl class="dati">
                    <div><dt>Formato di import</dt><dd>{{ \App\Models\Conto::FORMATI_IMPORT[$conto->tipo][$conto->formato_import] ?? $conto->formato_import }}</dd></div>
                    <div><dt>Riga del Modello D</dt><dd>{{ \App\Models\Conto::RIGHE_MODELLO_D[$conto->riga_modello_d] }}</dd></div>
                    <div><dt>Aperto il</dt><dd>{{ $conto->aperto_il->format('d/m/Y') }}</dd></div>
                    @if ($conto->chiuso()) <div><dt>Chiuso il</dt><dd>{{ $conto->chiuso_il->format('d/m/Y') }}</dd></div> @endif
                    <div><dt>Saldo di partenza</dt><dd>{{ \App\Euro::formatta($conto->saldo_iniziale) }} €</dd></div>
                    @if (\App\Models\ImportEstratto::lettorePer($conto) || $conto->tipo === 'cassa')
                        @php($fino = $conto->riconciliatoFinoAl())
                        <div><dt>Riconciliazione</dt><dd>{{ $fino ? 'Riconciliato fino al '.$fino->format('d/m/Y') : 'Non riconciliato' }}</dd></div>
                    @endif
                </dl>
                @if ($conto->tipo === 'cassa')
                    <p><a href="{{ route('conti.conte', $conto) }}" class="link" data-merlo="conta-di-cassa">Conta di cassa</a></p>
                @endif
                @if (\App\Models\ImportEstratto::lettorePer($conto))
                    @can('importare_e_confermare')
                        <p><a href="{{ route('import.indice', $conto) }}" class="link" data-merlo="importa-estratto">Importa un estratto</a></p>
                    @endcan
                @endif
                @can('gestire_regole')
                    @unless ($conto->chiuso())
                        <p><a href="{{ route('conti.modifica', $conto) }}" class="link">Modifica</a></p>
                        <form method="POST" action="{{ route('conti.chiudi', $conto) }}" class="modulo-in-riga" onsubmit="return confirm('Chiudere il conto? Non si può riaprire e le assegnazioni limitate a questo conto decadono.')">
                            @csrf
                            <label>Chiudi il conto il
                                <input type="date" name="chiuso_il" value="{{ today()->toDateString() }}" min="{{ $conto->aperto_il->toDateString() }}" max="{{ today()->toDateString() }}" required>
                            </label>
                            <button type="submit" class="bottone">Chiudi il conto</button>
                            <span class="nota">Le assegnazioni limitate a questo conto decadono.</span>
                        </form>
                    @endunless
                @endcan
            </li>
        @empty
            <li class="vuoto">Nessun conto: il Tesoriere li crea da qui.</li>
        @endforelse
    </ul>
@endsection
