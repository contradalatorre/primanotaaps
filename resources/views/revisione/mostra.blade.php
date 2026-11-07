@extends('layouts.app')

@section('titolo', 'Revisione · '.$import->conto->nome)

@section('contenuto')
    {{-- La vista (Tabella o Schede) è scelta prima del disegno della pagina, per non far lampeggiare l'altra. Senza script: Schede. --}}
    <script>try { var v = localStorage.getItem('vista-revisione'); document.documentElement.dataset.vista = v || (window.innerWidth < 900 ? 'schede' : 'tabella'); } catch (e) {}</script>

    @php
        $euro = fn (int $c) => \App\Euro::formatta($c).' €';
        $catena = $revisione->catena();
        $totale = $revisione->movimenti->count();
        $pronte = $revisione->conteggio('pronte');
        $url = fn (array $parametri = []) => route('import.revisione', [$import, 'passo' => $passo] + $parametri);
    @endphp

    <div class="revisione" data-revisione @if ($dettaglio) data-dettaglio @endif>
        <header class="revisione-testa">
            <div>
                <h1>Revisione dei movimenti</h1>
                <p class="nota">{{ $import->conto->nome }} · dal {{ $import->periodo_dal->format('d/m/Y') }} al {{ $import->periodo_al->format('d/m/Y') }}
                    · <a href="{{ route('import.mostra', $import) }}" class="link">Vedi l’import</a></p>
                <p>{{ ($resta = $revisione->cosaResta()) ? 'Restano '.$resta.'.' : 'Tutti i movimenti sono confermati.' }}</p>
            </div>
        </header>

        {{-- Catena del saldo e barra dei confermati. Sul telefono la catena si riduce alla riga del saldo e si espande. --}}
        <section class="catena-e-barra" aria-label="Saldo e avanzamento">
            <details class="catena" open data-catena>
                <summary>
                    <span>Saldo al {{ $import->periodo_al->format('d/m/Y') }} <strong class="importo">{{ $euro($catena['finale']) }}</strong></span>
                    @if ($catena['uguale'])
                        <span class="pill pill-confermato">@include('revisione.icona', ['nome' => 'ok'])Uguale all’estratto</span>
                    @else
                        <span class="pill pill-attenzione">@include('revisione.icona', ['nome' => 'attenzione'])Diverso dall’estratto: il file dice {{ $euro($catena['estratto']) }}</span>
                    @endif
                </summary>
                <dl class="catena-termini">
                    <div><dt>Saldo al {{ $import->periodo_dal->copy()->subDay()->format('d/m/Y') }}</dt><dd>{{ $euro($catena['iniziale']) }}</dd></div>
                    <div><dt>+ Entrate</dt><dd>{{ $euro($catena['entrate']) }}</dd></div>
                    <div><dt>− Uscite</dt><dd>{{ $euro($catena['uscite']) }}</dd></div>
                    <div><dt>= Saldo al {{ $import->periodo_al->format('d/m/Y') }}</dt><dd>{{ $euro($catena['finale']) }}</dd></div>
                </dl>
            </details>
            <div class="barra">
                <span class="nota">Confermati {{ $revisione->confermati() }} su {{ $totale }}</span>
                <div class="barra-pista" role="progressbar" aria-label="Movimenti confermati" aria-valuemin="0" aria-valuenow="{{ $revisione->confermati() }}" aria-valuemax="{{ $totale }}">
                    <span style="width: {{ $totale ? round($revisione->confermati() / $totale * 100) : 0 }}%"></span>
                </div>
            </div>
        </section>

        <nav class="passi" aria-label="Cosa resta da fare">
            @foreach (\App\Revisione::PASSI as $chiave => $etichetta)
                <a href="{{ route('import.revisione', [$import, 'passo' => $chiave]) }}" class="passo" data-passo="{{ $chiave }}" data-conteggio="{{ $revisione->conteggio($chiave) }}" @if ($chiave === $passo) aria-current="page" @endif>
                    <span>{{ $etichetta }}</span>
                    <strong>{{ $revisione->conteggio($chiave) }}</strong>
                </a>
            @endforeach
        </nav>

        <div class="revisione-lavoro">
            <section class="elenco" aria-label="{{ \App\Revisione::PASSI[$passo] }}">
                <div class="elenco-testa">
                    <h2>{{ \App\Revisione::PASSI[$passo] }} <span class="nota">{{ $movimenti->count() }} {{ $movimenti->count() === 1 ? 'movimento' : 'movimenti' }}</span></h2>
                    <div class="vista" role="group" aria-label="Vista">
                        <button type="button" data-vista="tabella" aria-pressed="false">Tabella</button>
                        <button type="button" data-vista="schede" aria-pressed="false">Schede</button>
                    </div>
                </div>
                <p class="nota">{{ [
                    'giroconti' => 'I giroconti spostano denaro tra due conti dell’associazione e non sono né entrate né uscite: li abbina l’app, in un prossimo passo.',
                    'pronte' => 'Raggruppate per tipologia e iniziativa. Controlla ogni gruppo e confermalo in un colpo; se un movimento è sbagliato, aprilo e correggilo prima.',
                    'decidere' => 'Apri ogni movimento e scegli la tipologia: senza non si conferma. Per più movimenti insieme, selezionali e imposta l’iniziativa.',
                    'tutti' => 'Tutti i movimenti dell’import, in ordine di data.',
                ][$passo] }}</p>

                @if ($movimenti->isEmpty())
                    <p class="vuoto">{{ [
                        'giroconti' => 'Nessun giroconto da controllare.',
                        'pronte' => 'Nessuna proposta da confermare.',
                        'decidere' => 'Niente da decidere: ogni movimento ha una proposta o è confermato.',
                        'tutti' => 'Questo import non ha movimenti.',
                    ][$passo] }}</p>
                @elseif ($passo === 'pronte')
                    @foreach ($revisione->gruppi($movimenti) as $etichetta => $gruppo)
                        <div class="gruppo">
                            <div>
                                <strong>{{ $etichetta }}</strong>
                                <span class="nota">{{ $gruppo->count() }} {{ $gruppo->count() === 1 ? 'movimento' : 'movimenti' }} · {{ $gruppo->map->fonteProposta()->unique()->join(', ') }}</span>
                            </div>
                            <strong class="importo">{{ $euro($gruppo->sum(fn ($m) => $m->verso === 'uscita' ? -$m->importo : $m->importo)) }}</strong>
                        </div>
                        <ul class="righe">
                            @foreach ($gruppo as $movimento) @include('revisione.riga') @endforeach
                        </ul>
                    @endforeach
                @else
                    <ul class="righe">
                        @foreach ($movimenti as $movimento) @include('revisione.riga') @endforeach
                    </ul>
                @endif
            </section>

            @if ($dettaglio)
                @include('revisione.dettaglio')
            @endif
        </div>
    </div>
@endsection
