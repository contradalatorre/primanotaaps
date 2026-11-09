@if ($percorsi->isNotEmpty())
    <section class="impara-sezione">
        <h2>{{ $titolo }}</h2>
        <p class="nota">{{ $nota }}</p>
        @foreach ($percorsi as $percorso)
            <h3 class="impara-percorso">{{ $percorso['titolo'] }}</h3>
            <ul class="schede">
                @foreach ($percorso['schede'] as $scheda)
                    @php
                        $m = $scheda['missione'];
                        $a = $scheda['avanzamento'];
                        $fatta = $a?->completata();
                        $inCorso = $a?->stato === 'in_corso';
                    @endphp
                    <li class="scheda" id="missione-{{ $m->id }}">
                        <div class="scheda-testa">
                            <strong>{{ $m->titolo }}</strong>
                            <span class="etichetta">{{ $m->durata }}</span>
                            @if ($fatta)<span class="etichetta">timbro ottenuto</span>
                            @elseif ($inCorso)<span class="etichetta">in corso, passo {{ $a->passo + 1 }} di {{ count($m->passi) }}</span>
                            @elseif ($a?->stato === 'saltata')<span class="etichetta">saltata</span>
                            @endif
                        </div>
                        @if (! $m->telefono)<p class="nota">Va meglio dal computer.</p>@endif
                        @if ($scheda['novita'])
                            <p class="avviso" role="status">Cosa è cambiato in questa missione: {{ $scheda['novita'] }} Puoi rifarla, se vuoi: il timbro resta.</p>
                        @endif
                        <div class="azioni">
                            @if ($inCorso)
                                <form method="POST" action="{{ route('impara.riprendi', $m->id) }}">@csrf<button type="submit" class="bottone">Riprendi</button></form>
                                <form method="POST" action="{{ route('impara.avvia', $m->id) }}">@csrf<button type="submit" class="link">Ricomincia da capo</button></form>
                            @else
                                <form method="POST" action="{{ route('impara.avvia', $m->id) }}">@csrf<button type="submit" class="bottone">{{ $fatta ? 'Rifai (versione rapida)' : 'Inizia' }}</button></form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endforeach
    </section>
@endif
