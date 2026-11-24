{{-- Le note di un movimento in sequenza, e il modulo per scriverne una. Il testo è di utenti: sempre `{{ }}`, mai `{!! !!}`.
     `$movimento`, `$note` (`Nota::di`); `$dubbioPreselezionato` per il ? della revisione. --}}
@php
    $utente = auth()->user();
    $data = fn ($d) => $d->format('d/m/Y H:i');
@endphp
<section class="note" aria-label="Note del movimento" id="note">
    <h2>Note <span class="nota">{{ $note->whereNull('eliminata_il')->count() }}</span></h2>
    <ul class="schede">
        @forelse ($note as $nota)
            <li class="scheda" id="nota-{{ $nota->id }}">
                <p class="nota">
                    <strong>{{ $nota->autore->name }}</strong> · {{ $data($nota->created_at) }}
                    @if ($nota->fase) · <span class="etichetta">aggiunta dopo {{ \App\Models\Nota::FASI[$nota->fase] ?? $nota->fase }}</span> @endif
                    @if ($nota->modificata_il) · modificata @endif
                    @if ($nota->dubbio) @if ($nota->chiuso_il) <span class="etichetta">Dubbio chiuso</span> @elseif (! $nota->eliminata()) @include('note.pill-dubbio') @endif @endif
                </p>
                @if ($nota->eliminata())
                    <p class="nota">Nota eliminata da {{ $nota->eliminataDa->name }}.</p>
                @elseif ($nota->oscurata())
                    <p class="nota">Nota oscurata da {{ $nota->oscurataDa->name }}: {{ $nota->motivazione_oscuramento }}</p>
                @else
                    <p class="testo-nota">{{ $nota->testo }}</p>
                @endif

                {{-- Chi scrive vede se le sue menzioni sono state viste, e da chi. --}}
                @if ($nota->user_id === $utente->id && ! $nota->eliminata() && ! $nota->oscurata())
                    @foreach ($nota->menzioni as $menzione)
                        <p class="nota">{{ $menzione->utente ? '@'.$menzione->utente->name : '@'.$menzione->ruolo->nome.' (ruolo)' }}:
                            {{ $menzione->vista_il ? 'vista da '.$menzione->vistaDa->name.' il '.$data($menzione->vista_il) : 'ancora da vedere' }}</p>
                    @endforeach
                @endif

                @if ($nota->modificabileDa($utente))
                    <details>
                        <summary class="link">Modifica o cancella (entro 24 ore dalla scrittura)</summary>
                        <form method="POST" action="{{ route('note.modifica', $nota) }}" class="modulo-stretto">
                            @csrf @method('PUT')
                            <label>Testo <textarea name="testo" required maxlength="1000" rows="3">{{ $nota->testo }}</textarea></label>
                            <button type="submit" class="bottone-piccolo">Salva la modifica</button>
                        </form>
                        <form method="POST" action="{{ route('note.elimina', $nota) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="link">Cancella la nota</button>
                        </form>
                    </details>
                @endif

                @can('oscurare_nota')
                    @unless ($nota->eliminata() || $nota->oscurata())
                        <details>
                            <summary class="link">Oscura la nota</summary>
                            <form method="POST" action="{{ route('note.oscura', $nota) }}" class="modulo-stretto">
                                @csrf
                                <label>Motivazione <input type="text" name="motivazione" required maxlength="1000"></label>
                                <button type="submit" class="bottone-piccolo">Oscura</button>
                            </form>
                        </details>
                    @endunless
                @endcan
            </li>
        @empty
            <li class="vuoto">Nessuna nota.</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ route('movimenti.note.crea', $movimento) }}" class="modulo-stretto" id="nuova-nota">
        @csrf
        <label>Scrivi una nota <textarea name="testo" required maxlength="1000" rows="3" @if ($dubbioPreselezionato ?? false) autofocus @endif>{{ old('testo') }}</textarea></label>
        <p class="nota">Per chiamare qualcuno scrivi @ e il suo nome o il nome di un ruolo, per esempio «@Tesoriere».</p>
        <label class="spunta"><input type="checkbox" name="dubbio" value="1" @checked(old('dubbio', $dubbioPreselezionato ?? false))> <span>È un dubbio: il movimento resta in dubbio finché non è confermato</span></label>
        <button type="submit" class="bottone">Aggiungi</button>
    </form>
</section>
