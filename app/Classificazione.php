<?php

namespace App;

use App\Models\Attivita;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\Regola;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La classificazione di un movimento in imputazioni (glossario: Imputazione) e la sua conferma manuale.
 * Le regole stanno qui e non nei controllori: la somma delle imputazioni è l'importo del movimento, area e attività
 * si ereditano dall'iniziativa e si possono cambiare, la voce Modello D non si sceglie (si ricava da area e
 * tipologia per l'esercizio del movimento) e una combinazione fuori tabella si rifiuta.
 */
final class Classificazione
{
    /**
     * Sostituisce le imputazioni del movimento con `$righe` (campi: importo, tipologia_id, iniziativa_id, area,
     * attivita_id, tag) e la controparte. Le righe senza alcun campo si ignorano; nessuna riga toglie la classificazione.
     * Un movimento non confermato con imputazioni nuove è «proposto» (da chi le ha scritte); uno confermato resta
     * confermato, a nome di chi lo ha riclassificato (#16: l'autore non riprende mai i diritti).
     *
     * @throws ValidationException con gli errori sotto `imputazioni.N.campo`
     */
    public static function salva(User $utente, Movimento $movimento, ?string $tipoControparte, array $righe): void
    {
        $righe = array_filter($righe, fn (array $r) => array_filter($r, fn ($v) => $v !== null && $v !== '' && $v !== []) !== []);
        $imputazioni = self::risolvi($movimento, $righe);

        DB::transaction(function () use ($utente, $movimento, $tipoControparte, $imputazioni) {
            self::bloccaEControlla($utente, $movimento);
            $prima = $movimento->istantanea();
            $firmaPrima = self::firma($movimento->imputazioni()->orderBy('id')->get()->map->only(self::CAMPI_DELLA_CLASSIFICAZIONE));
            self::scriviImputazioni($movimento, $imputazioni);
            // I tag non hanno effetti (spec #33): cambiandoli soli lo stato non si muove.
            $cambiata = $firmaPrima !== self::firma(collect($imputazioni)->map(fn ($i) => Arr::only($i, self::CAMPI_DELLA_CLASSIFICAZIONE)));
            $stato = [];
            if ($movimento->confermato()) {
                // Confermato resta: chi lo riclassifica lo riconferma a suo nome (la conferma vale come verifica) e
                // l'autore non riprende mai i diritti di ritiro e modifica. Se non si confermerebbe più, non si salva.
                if (($problemi = self::problemiDiConferma($movimento)) !== []) {
                    throw ValidationException::withMessages(['conferma' => $problemi]);
                }
                $stato = $cambiata ? ['confermato_da' => $utente->id, 'confermato_il' => now()] : [];
            } elseif ($cambiata || $movimento->regola_id !== null) {
                // Salvare a mano una proposta di regola ne fa una scelta della persona (anche senza cambiare nulla): le regole non la toccano più.
                $stato = ['stato' => $imputazioni === [] ? 'da_classificare' : 'proposto', 'proposto_da' => $imputazioni === [] ? null : $utente->id, 'regola_id' => null];
            }
            $movimento->forceFill(['tipo_controparte' => $tipoControparte ?: null] + $stato)->save();
            RegistroModifica::registra($utente, $movimento, 'classificato', $prima, $movimento->istantanea());
            if ($cambiata && $movimento->confermato()) {
                Note::chiudiDubbi($utente, $movimento); // riclassificare un confermato lo riconferma: scioglie il dubbio come la conferma
            }
        });
    }

    /** @param list<array<string,mixed>> $imputazioni come le restituisce `risolvi` */
    private static function scriviImputazioni(Movimento $movimento, array $imputazioni): void
    {
        $movimento->imputazioni()->delete(); // le righe dei tag seguono con la chiave esterna
        foreach ($imputazioni as $dati) {
            $tag = $dati['tag'];
            unset($dati['tag']);
            $movimento->imputazioni()->create($dati)->tag()->sync($tag);
        }
    }

    /**
     * Le imputazioni che la regola propone per il movimento (una sola, per tutto l'importo), o null se non si possono
     * proporre: tipologia o iniziativa archiviata, combinazione fuori dalla tabella delle voci, area non ricavabile.
     * Non scrive nulla: la usano anche l'anteprima e il controllo di chi applica.
     *
     * @return list<array<string,mixed>>|null
     */
    public static function imputazioniDellaRegola(Movimento $movimento, Regola $regola): ?array
    {
        // Una regola con un elemento archiviato non propone più, nemmeno dove la proposta c'è già (`risolvi` lascia ripetere quello che il movimento ha).
        if ($regola->archiviata()) {
            return null;
        }
        try {
            return self::risolvi($movimento, [[
                'tipologia_id' => $regola->tipologia_id, 'iniziativa_id' => $regola->iniziativa_id, 'area' => $regola->area,
                'attivita_id' => $regola->attivita_id, 'tag' => $regola->tag->modelKeys(),
            ]]);
        } catch (ValidationException) {
            return null;
        }
    }

    /**
     * Mette sul movimento la proposta della regola, o la toglie (`$regola` null: torna da classificare). Per chi ha già
     * bloccato la riga e controllato che sia da classificare o proposta da una regola: i permessi sono di chi salva la
     * regola, non del movimento.
     *
     * @param  list<array<string,mixed>>  $imputazioni quelle di `imputazioniDellaRegola`, già controllate da chi chiama
     * @param  ?string  $tipoDiPrima il socio o terzo che aveva la regola di prima quando ha proposto (non quello di adesso: può essere stata appena modificata)
     */
    public static function proponiDaRegola(User $utente, Movimento $movimento, ?Regola $regola, array $imputazioni, ?string $tipoDiPrima = null): void
    {
        $prima = $movimento->istantanea();
        self::scriviImputazioni($movimento, $imputazioni);
        // Socio o terzo: si toglie solo quello che aveva messo la regola di prima; una scelta a mano resta e vince sulla regola nuova.
        $tipo = $movimento->tipo_controparte;
        if ($tipo !== null && $movimento->regola_id !== null && $tipo === $tipoDiPrima) {
            $tipo = null;
        }
        $tipo ??= $regola?->tipo_controparte;
        $movimento->forceFill(['stato' => $regola ? 'proposto' : 'da_classificare', 'proposto_da' => null, 'regola_id' => $regola?->id, 'tipo_controparte' => $tipo])->save();
        RegistroModifica::registra($utente, $movimento, $regola ? 'proposto_da_regola' : 'proposta_di_regola_tolta', $prima, $movimento->istantanea());
    }

    /** Quello che fa cambiare la classificazione: i tag no. */
    private const CAMPI_DELLA_CLASSIFICAZIONE = ['importo', 'area', 'attivita_id', 'iniziativa_id', 'tipologia_id'];

    private static function firma(Collection $righe): string
    {
        return json_encode($righe->map(fn ($r) => array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $r))->values());
    }

    /**
     * Conferma il movimento: ogni imputazione ha la tipologia, la somma è l'importo, il verso torna e la voce c'è
     * (importo, verso e data del movimento possono essere cambiati dopo la classificazione).
     *
     * @throws ValidationException sotto `conferma`
     */
    public static function conferma(User $utente, Movimento $movimento, string $versioneVista): void
    {
        // Controlli e scrittura nella stessa transazione, a riga bloccata: un salvataggio che arriva in mezzo
        // non può lasciare confermata un'imputazione senza tipologia.
        DB::transaction(function () use ($utente, $movimento, $versioneVista) {
            self::blocca($movimento);
            $movimento->refresh();
            if (! hash_equals(self::versione($movimento), $versioneVista)) {
                throw ValidationException::withMessages(['conferma' => 'Il movimento è cambiato dopo che lo hai aperto: rileggilo prima di confermarlo.']);
            }
            $problemi = self::problemiDiConferma($movimento);
            if ($problemi !== []) {
                throw ValidationException::withMessages(['conferma' => $problemi]);
            }

            $prima = $movimento->istantanea();
            $movimento->forceFill(['stato' => 'confermato', 'confermato_da' => $utente->id, 'confermato_il' => now()])->save();
            RegistroModifica::registra($utente, $movimento, 'confermato', $prima, $movimento->istantanea());
            Note::chiudiDubbi($utente, $movimento); // la conferma scioglie il dubbio (#16)
        });
    }

    /** Un'impronta di quello che si conferma (classificazione, importo, data, giustificativi): la conferma vale per la versione vista. */
    public static function versione(Movimento $movimento, bool $relazioniCaricate = false): string
    {
        return hash('sha256', json_encode($movimento->istantanea($relazioniCaricate)));
    }

    /** @return list<string> */
    private static function problemiDiConferma(Movimento $movimento): array
    {
        $imputazioni = $movimento->imputazioni()->with('tipologia')->get();
        $problemi = [];
        if ($imputazioni->isEmpty()) {
            $problemi[] = 'Il movimento non ha imputazioni: classificalo prima di confermarlo.';
        }
        if ($imputazioni->contains(fn (Imputazione $i) => $i->tipologia_id === null)) {
            $problemi[] = 'Ogni imputazione ha bisogno della tipologia: senza non si conferma.';
        }
        if ($imputazioni->isNotEmpty() && $imputazioni->sum('importo') !== $movimento->importo) {
            $problemi[] = 'La somma delle imputazioni non è più l’importo del movimento: rifai la classificazione.';
        }
        if ($imputazioni->contains(fn (Imputazione $i) => $i->tipologia && $i->tipologia->verso !== $movimento->verso)) {
            $problemi[] = 'Il verso del movimento non corrisponde più alla tipologia: rifai la classificazione.';
        }
        if ($imputazioni->contains(fn (Imputazione $i) => $i->tipologia_id !== null && $i->voce() === null)) {
            $problemi[] = 'La combinazione di area e tipologia non è ammessa per l’esercizio del movimento: rifai la classificazione.';
        }

        return $problemi;
    }

    /** Un movimento eliminato nel frattempo non si trova più: 404, non lo si ripesca con refresh() senza scope. */
    public static function blocca(Movimento $movimento): void
    {
        Movimento::whereKey($movimento->id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Da chiamare a inizio transazione prima di scrivere: blocca la riga, la rilegge e rifà il controllo di chi può
     * (il Tesoriere può aver confermato dopo il controllo del controllore: l'autore non scrive più).
     */
    public static function bloccaEControlla(User $utente, Movimento $movimento): void
    {
        self::blocca($movimento);
        $movimento->refresh();
        abort_unless($movimento->classificabileDa($utente), 403);
    }

    /**
     * Le righe con i valori che si salvano: importo in centesimi, area e attività ereditate dall'iniziativa se non
     * sono scelte. Tutti gli errori insieme, così la pagina li mostra accanto ai campi.
     *
     * @return list<array<string,mixed>>
     */
    private static function risolvi(Movimento $movimento, array $righe): array
    {
        $esercizio = $movimento->data->year;
        $errori = [];
        // Quello che il movimento ha già si può ripetere anche se nel frattempo è stato archiviato.
        $esistenti = $movimento->imputazioni()->with('tag:id')->get();
        $giaUsati = [
            'tipologia_id' => $esistenti->pluck('tipologia_id')->all(), 'iniziativa_id' => $esistenti->pluck('iniziativa_id')->all(),
            'attivita_id' => $esistenti->pluck('attivita_id')->all(), 'tag' => $esistenti->flatMap->tag->pluck('id')->all(),
        ];
        $scegliibile = fn ($elemento, string $campo, $id) => ! $elemento->archiviata() || in_array($id, $giaUsati[$campo], true);

        $risolte = [];
        foreach ($righe as $i => $riga) {
            $chiave = fn (string $campo) => "imputazioni.$i.$campo";
            $importo = (string) ($riga['importo'] ?? '');
            if ($importo === '' && count($righe) === 1) {
                $centesimi = $movimento->importo;
            } else {
                $centesimi = Euro::daTesto($importo);
                if ($centesimi === null || $centesimi <= 0) {
                    $errori[$chiave('importo')] = 'Scrivi l’importo in euro, più di zero e con al più due decimali (es. 12,50).';
                }
            }

            $iniziativa = ($riga['iniziativa_id'] ?? '') === '' ? null : Iniziativa::with('predefiniti')->find($riga['iniziativa_id']);
            if ($iniziativa && ! $scegliibile($iniziativa, 'iniziativa_id', $iniziativa->id)) {
                $errori[$chiave('iniziativa_id')] = "L’iniziativa «{$iniziativa->nome}» è archiviata: non si sceglie più.";
            }
            $predefiniti = $iniziativa?->predefinitiPer($esercizio);

            $area = ($riga['area'] ?? '') !== '' ? Area::from($riga['area']) : $predefiniti?->area;
            if ($area === null) {
                $errori[$chiave('area')] = 'Scegli l’area: l’iniziativa non ne propone una per il '.$esercizio.'.';
            }

            // L'attività dell'iniziativa vale solo se l'area è rimasta la sua.
            $attivitaScelta = ($riga['attivita_id'] ?? '') !== '';
            $attivitaId = $attivitaScelta ? (int) $riga['attivita_id'] : ($predefiniti?->area === $area ? $predefiniti?->attivita_id : null);
            if ($attivitaId !== null) {
                $attivita = Attivita::find($attivitaId);
                if ($attivita->area !== $area) {
                    $errori[$chiave('attivita_id')] = "L’attività «{$attivita->nome}» non è dell’area {$area?->value}.";
                } elseif ($attivitaScelta && ! $scegliibile($attivita, 'attivita_id', $attivita->id)) { // quella ereditata resta anche se archiviata
                    $errori[$chiave('attivita_id')] = "L’attività «{$attivita->nome}» è archiviata: non si sceglie più.";
                }
            }

            $tipologiaId = ($riga['tipologia_id'] ?? '') !== '' ? (int) $riga['tipologia_id'] : null;
            if ($tipologiaId !== null) {
                $tipologia = Tipologia::find($tipologiaId);
                if (! $scegliibile($tipologia, 'tipologia_id', $tipologia->id)) {
                    $errori[$chiave('tipologia_id')] = "La tipologia «{$tipologia->nome}» è archiviata: non si sceglie più.";
                } elseif ($tipologia->verso !== $movimento->verso) {
                    $errori[$chiave('tipologia_id')] = "«{$tipologia->nome}» è una tipologia di ".Tipologia::VERSI[$tipologia->verso].', il movimento è un’'.Movimento::VERSI[$movimento->verso].'.';
                } elseif ($area !== null && ! DB::table('voci_modello_d')->where(['esercizio' => $esercizio, 'area' => $area->value, 'tipologia_id' => $tipologiaId])->exists()) {
                    $errori[$chiave('tipologia_id')] = "La tipologia «{$tipologia->nome}» non è ammessa nell’area {$area->value} per il $esercizio.";
                }
            }

            $tag = array_map('intval', $riga['tag'] ?? []);
            foreach (Tag::whereIn('id', $tag)->get() as $t) {
                if (! $scegliibile($t, 'tag', $t->id)) {
                    $errori[$chiave('tag')] = "Il tag «{$t->nome}» è archiviato: non si sceglie più.";
                }
            }

            $risolte[] = ['importo' => $centesimi, 'area' => $area?->value, 'attivita_id' => $attivitaId, 'iniziativa_id' => $iniziativa?->id,
                'tipologia_id' => $tipologiaId, 'tag' => $tag];
        }

        if ($errori === [] && $risolte !== [] && array_sum(array_column($risolte, 'importo')) !== $movimento->importo) {
            $errori['imputazioni'] = 'La somma delle imputazioni ('.Euro::formatta(array_sum(array_column($risolte, 'importo'))).' €) deve essere pari all’importo del movimento ('.Euro::formatta($movimento->importo).' €).';
        }
        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }

        return $risolte;
    }
}
