{{-- Il passo Giroconti (#47): una scheda per proposta. L'operatore conferma ogni abbinamento; mai in silenzio, mai in gruppo. --}}
<ul class="schede" data-giroconti>
    @foreach ($revisione->giroconti() as $proposta)
        @php
            $m = $proposta['movimento'];
            $c = $proposta['controparte'];
            $mio = $m->import_estratto_id === $import->id ? $m : $c;
            $mezzo = fn ($x) => $x->conto->nome.' · '.$x->data->format('d/m/Y').' · '.($x->verso === 'uscita' ? '−' : '+').$euro($x->importo);
        @endphp
        <li class="scheda" id="giroconto-{{ $m->id }}">
            <div class="scheda-testa">
                <strong>{{ $proposta['regola']['nome'] }}</strong>
                @if ($proposta['orfana'])
                    <span class="pill pill-attenzione">@include('revisione.icona', ['nome' => 'attenzione'])Senza corrispondenza da {{ $proposta['giorni'] }} giorni</span>
                @else
                    <span class="pill pill-proposto">@include('revisione.icona', ['nome' => 'proposto'])Proposto</span>
                @endif
            </div>
            <p>{{ $m->descrizione }}<br><span class="nota">{{ $mezzo($m) }}</span></p>
            @if ($proposta['tipo'] === 'coppia')
                <p>con {{ $c->descrizione }}<br><span class="nota">{{ $mezzo($c) }}</span></p>
                <form method="POST" action="{{ route('import.giroconti.conferma', $import) }}">
                    @csrf
                    <input type="hidden" name="movimento_a" value="{{ $m->id }}">
                    <input type="hidden" name="movimento_b" value="{{ $c->id }}">
                    <button type="submit" class="bottone-piccolo">Conferma il giroconto</button>
                </form>
            @elseif ($proposta['tipo'] === 'meta')
                <p class="nota">Manca la metà sull’altro conto ({{ \App\Models\Conto::TIPI[$proposta['regola']['con']] }}): la registri qui, con «{{ str_replace('{conto}', $m->conto->nome, $proposta['regola']['meta']) }}».</p>
                @if ($proposta['conti']->isEmpty())
                    <p class="nota">Nessun conto di questo tipo è aperto: non c’è dove scrivere la metà (serve un conto non chiuso e già attivo il {{ $m->data->format('d/m/Y') }}).</p>
                @else
                    <form method="POST" action="{{ route('import.giroconti.meta', $import) }}" class="modulo-in-riga">
                        @csrf
                        <input type="hidden" name="movimento" value="{{ $m->id }}">
                        <label>Sul conto
                            <select name="conto_id">
                                @foreach ($proposta['conti'] as $conto)<option value="{{ $conto->id }}">{{ $conto->nome }}</option>@endforeach
                            </select>
                        </label>
                        <button type="submit" class="bottone-piccolo">Registra e conferma</button>
                    </form>
                @endif
            @else
                <p class="nota">Aspetta la metà dall’altro conto: arriva con il suo estratto o con un movimento scritto a mano.</p>
            @endif
            <p class="nota"><a href="{{ route('import.revisione', [$import, 'passo' => 'tutti', 'movimento' => $mio->id]) }}#riga-{{ $mio->id }}" class="link">Non è un giroconto? Aprilo e classificalo</a></p>
        </li>
    @endforeach
</ul>
