@php
    $campi = ['tipo' => 'Tipo', 'nome' => 'Nome', 'formato_import' => 'Formato di import', 'riga_modello_d' => 'Riga del Modello D',
        'aperto_il' => 'Aperto il', 'chiuso_il' => 'Chiuso il', 'saldo_iniziale' => 'Saldo di partenza', 'conto_id' => 'Conto',
        'data' => 'Data', 'importo' => 'Importo', 'verso' => 'Verso', 'descrizione' => 'Descrizione',
        'valuta_originale' => 'Valuta originale', 'stato' => 'Stato', 'area' => 'Area',
        'spiegazione' => 'Spiegazione', 'codice' => 'Sezione', 'titolo' => 'Titolo', 'corpo' => 'Testo', 'proprieta' => 'Proprietà fiscali', 'archiviata_il' => 'Archiviata il', 'predefiniti' => 'Area e attività predefinite',
        'imputazioni' => 'Imputazioni', 'tipo_controparte' => 'La controparte è', 'giustificativi' => 'Giustificativi',
        'testo' => 'Testo della nota', 'dubbio' => 'Dubbio', 'saldo_finale' => 'Saldo finale', 'righe' => 'Righe',
        'importo_contato' => 'Contanti contati', 'saldo_app' => 'Saldo dell’app', 'differenza' => 'Differenza', 'correzione_id' => 'Movimento di correzione n.', 'contata_con' => 'Contata insieme a',
        'dal_esercizio' => 'Valide dall’esercizio', 'denominazione' => 'Denominazione', 'codice_fiscale' => 'Codice fiscale', 'numero_runts' => 'Numero RUNTS', 'affiliazione_rete' => 'Affiliazione a una rete nazionale (art. 85 c. 4)', 'voci_copiate' => 'Voci copiate', 'predefiniti_copiati' => 'Predefiniti copiati', 'copiati_da' => 'Copiati dall’esercizio',
        'esercizio' => 'Esercizio', 'dipendenti_medi' => 'Dipendenti medi', 'attivo' => 'Totale dell’attivo', 'criterio_riparto' => 'Riparto dei costi promiscui', 'criterio_secondarieta' => 'Criterio di secondarietà confermato', 'figurativi_dichiarati' => 'Costi figurativi dichiarati completi', 'soglia_vicino' => '«Vicino alla soglia» oltre il (% del limite)',
        'periodo_dal' => 'Dal', 'periodo_al' => 'Al', 'nuovi' => 'Nuovi', 'gia_presenti' => 'Già presenti', 'in_conflitto' => 'In conflitto'];
    $euro = ['importo', 'saldo_iniziale', 'saldo_finale', 'importo_contato', 'saldo_app', 'attivo'];
    $valore = fn ($chiave, $v) => $v === null ? '–' : (in_array($chiave, $euro, true) ? \App\Euro::formatta($v).' €'
        : ($chiave === 'differenza' ? ($v > 0 ? '+' : '').\App\Euro::formatta($v).' €'
        : ($chiave === 'contata_con' ? (\App\Models\User::find($v)?->name ?? 'n. '.$v) : $v)));
    // Le righe di una modifica elencano solo ciò che è cambiato; creazione ed eliminazione elencano tutto.
    $chiavi = array_keys(($riga->prima ?? []) + ($riga->dopo ?? []));
    $mostra = collect($chiavi)->filter(fn ($k) => ! ($riga->prima && $riga->dopo) || ($riga->prima[$k] ?? null) !== ($riga->dopo[$k] ?? null));
    $azioni = ['inserito' => 'ha inserito', 'modificato' => 'ha modificato', 'ritirato' => 'ha ritirato', 'eliminato' => 'ha eliminato', 'creato' => 'ha creato', 'chiuso' => 'ha chiuso',
        'archiviato' => 'ha archiviato', 'ripristinato' => 'ha ripristinato', 'classificato' => 'ha classificato', 'confermato' => 'ha confermato',
        'importato' => 'ha importato', 'annullato' => 'ha annullato', 'conflitto_deciso' => 'ha deciso un conflitto di',
        'scritta' => 'ha scritto', 'modificata' => 'ha modificato', 'eliminata' => 'ha eliminato', 'oscurata' => 'ha oscurato', 'dubbio_chiuso' => 'ha chiuso il dubbio di', 'aperto' => 'ha aperto',
        'creata' => 'ha creato', 'riordinata' => 'ha riordinato', 'giroconto_confermato' => 'ha confermato il giroconto di', 'giroconto_sciolto' => 'ha sciolto il giroconto di', 'conta' => 'ha fatto una conta di cassa:', 'saldo_dichiarato' => 'ha dichiarato il saldo di'];
    $oggetti = ['conto' => 'il conto', 'movimento' => 'il movimento', 'tipologia' => 'la tipologia', 'attivita' => 'l’attività', 'iniziativa' => 'l’iniziativa', 'tag' => 'il tag', 'import' => 'l’import', 'manuale' => 'la sezione del manuale', 'nota' => 'la nota', 'regola' => 'la regola', 'impostazioni' => 'le impostazioni dell’associazione dall’esercizio', 'esercizio' => 'l’esercizio', 'dati_esercizio' => 'i dati dell’esercizio', 'riga_figurativa' => 'una riga figurativa dell’esercizio'];
@endphp
<li class="scheda">
    <p>
        <strong>{{ $riga->user->name }}</strong> {{ $azioni[$riga->azione] ?? $riga->azione }} {{ $oggetti[$riga->oggetto_tipo] ?? 'il movimento' }} {{ match ($riga->oggetto_tipo) {
            'manuale' => '§ '.(($riga->dopo ?? $riga->prima)['codice'] ?? $riga->oggetto_id),
            'impostazioni' => (($riga->dopo ?? $riga->prima)['dal_esercizio'] ?? $riga->oggetto_id), // il dato che le identifica per chi legge: l'esercizio, non l'id della riga
            'dati_esercizio', 'riga_figurativa' => (($riga->dopo ?? $riga->prima)['esercizio'] ?? ''),
            default => 'n. '.$riga->oggetto_id,
        } }}
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
