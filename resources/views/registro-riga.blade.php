@php
    $campi = ['tipo' => 'Tipo', 'nome' => 'Nome', 'formato_import' => 'Formato di import', 'riga_modello_d' => 'Riga del Modello D',
        'aperto_il' => 'Aperto il', 'chiuso_il' => 'Chiuso il', 'saldo_iniziale' => 'Saldo di partenza', 'conto_id' => 'Conto',
        'data' => 'Data', 'importo' => 'Importo', 'verso' => 'Verso', 'descrizione' => 'Descrizione',
        'valuta_originale' => 'Valuta originale', 'stato' => 'Stato', 'area' => 'Area',
        'spiegazione' => 'Spiegazione', 'proprieta' => 'Proprietà fiscali', 'archiviata_il' => 'Archiviata il', 'predefiniti' => 'Area e attività predefinite',
        'imputazioni' => 'Imputazioni', 'tipo_controparte' => 'La controparte è', 'giustificativi' => 'Giustificativi'];
    $valore = fn ($chiave, $v) => $v === null ? '–' : (in_array($chiave, ['importo', 'saldo_iniziale'], true) ? \App\Euro::formatta($v).' €' : $v);
    // Le righe di una modifica elencano solo ciò che è cambiato; creazione ed eliminazione elencano tutto.
    $chiavi = array_keys(($riga->prima ?? []) + ($riga->dopo ?? []));
    $mostra = collect($chiavi)->filter(fn ($k) => ! ($riga->prima && $riga->dopo) || ($riga->prima[$k] ?? null) !== ($riga->dopo[$k] ?? null));
    $azioni = ['inserito' => 'ha inserito', 'modificato' => 'ha modificato', 'ritirato' => 'ha ritirato', 'eliminato' => 'ha eliminato', 'creato' => 'ha creato', 'chiuso' => 'ha chiuso',
        'archiviato' => 'ha archiviato', 'ripristinato' => 'ha ripristinato', 'classificato' => 'ha classificato', 'confermato' => 'ha confermato'];
    $oggetti = ['conto' => 'il conto', 'movimento' => 'il movimento', 'tipologia' => 'la tipologia', 'attivita' => 'l’attività', 'iniziativa' => 'l’iniziativa', 'tag' => 'il tag'];
@endphp
<li class="scheda">
    <p>
        <strong>{{ $riga->user->name }}</strong> {{ $azioni[$riga->azione] ?? $riga->azione }}
        {{ $oggetti[$riga->oggetto_tipo] ?? 'il movimento' }} n. {{ $riga->oggetto_id }}
        <span class="nota">· {{ $riga->created_at->format('d/m/Y H:i') }}</span>
    </p>
    @if ($riga->motivazione) <p>Motivazione: {{ $riga->motivazione }}</p> @endif
    <dl class="dati">
        @foreach ($mostra as $chiave)
            <div>
                <dt>{{ $campi[$chiave] ?? $chiave }}</dt>
                <dd>
                    @if ($riga->prima && $riga->dopo) {{ $valore($chiave, $riga->prima[$chiave] ?? null) }} → {{ $valore($chiave, $riga->dopo[$chiave] ?? null) }}
                    @else {{ $valore($chiave, ($riga->prima ?? $riga->dopo)[$chiave] ?? null) }}
                    @endif
                </dd>
            </div>
        @endforeach
    </dl>
</li>
