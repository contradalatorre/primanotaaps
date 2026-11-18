<?php

namespace App;

use App\Import\EsempiDalFoglio;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\ConversioneCausale;
use App\Models\Iniziativa;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tag;
use App\Models\Tipologia;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La tabella di conversione delle vecchie causali al lavoro sulla cassa del foglio (#64, #19): propone la classificazione sui movimenti che entrano
 * con l'import, e quando il Tesoriere cambia una riga la riapplica ai movimenti *da classificare* o *proposti dalla tabella* (mai a una scelta
 * a mano) ed elenca i *confermati* da correggere, che restano come sono (come per le regole, #42). Dove la tabella non basta (causale assente,
 * senza tipologia, segno diverso, giroconto) il movimento resta da classificare con un dubbio che dice perché.
 * Dopo la data di passaggio la tabella è chiusa (`chiusa`).
 */
final class Conversioni
{
    /** Gli inizi dei dubbi che scrive questo modulo: `riapplica` chiude i primi quando la riga li risolve, mai quello dei giroconti. */
    private const DUBBIO_TABELLA = 'Tabella di conversione: ';

    private const DUBBIO_GIROCONTO = 'Giroconto da abbinare: ';

    /** La tabella si chiude con l'import: quando la data di passaggio di una cassa del foglio è passata. */
    public static function chiusa(): bool
    {
        return Conto::where('formato_import', 'foglio_cassa')->get()->contains(fn (Conto $c) => $c->foglioChiuso());
    }

    /**
     * La proposta sui movimenti appena entrati dal foglio. Per chi ha già bloccato il conto e i movimenti (la conferma dell'import): i permessi sono di chi importa.
     *
     * @param  Collection<int, Movimento>  $movimenti  da classificare, appena inseriti
     */
    public static function applicaAiNuovi(User $utente, Collection $movimenti): int
    {
        $tabella = self::tabella();
        $movimenti->load('conto');

        return $movimenti->sum(fn (Movimento $m) => (int) self::assegna($utente, $m, $tabella));
    }

    /**
     * La riga è stata cambiata: si riapplica ai movimenti da classificare o proposti dalla tabella con quella causale. Chiama chi ha già bloccato
     * i conti (come `Regole`) e riletto il permesso.
     *
     * @return int quanti movimenti hanno cambiato proposta
     */
    public static function riapplica(User $utente, ConversioneCausale $riga): int
    {
        $tabella = self::tabella();
        $chiave = EsempiDalFoglio::chiave($riga->causale);
        $candidati = Movimento::with('conto')->whereNotNull('import_estratto_id')->whereNotNull('causale')
            ->whereHas('conto', fn (Builder $c) => $c->where('formato_import', 'foglio_cassa'))
            ->where(fn (Builder $q) => $q->where('stato', 'da_classificare')->orWhere(fn (Builder $q) => $q->where('stato', 'proposto')->where('conversione_causale_id', $riga->id)))
            ->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (Movimento $m) => EsempiDalFoglio::chiave($m->causale) === $chiave);

        return $candidati->sum(fn (Movimento $m) => (int) self::assegna($utente, $m, $tabella));
    }

    /**
     * I movimenti confermati che la tabella aveva proposto e che con la riga di adesso avrebbero un'altra classificazione: restano come sono, si
     * elencano per correggerli (dalla revisione del loro import, con la barra dei selezionati).
     * shortcut: una query di confronto per movimento confermato; sono quelli del foglio (qualche centinaio), da ripensare se diventano migliaia.
     *
     * @return Collection<int, Movimento>
     */
    public static function daCorreggere(): Collection
    {
        $perId = self::tabella()->keyBy('id');

        return Movimento::with('conversioneCausale')->where('stato', 'confermato')->whereNotNull('conversione_causale_id')->orderBy('data')->orderBy('id')->get()
            ->filter(function (Movimento $m) use ($perId) {
                $riga = $perId->get($m->conversione_causale_id);
                $imputazioni = $riga ? Classificazione::imputazioniDellaConversione($m, $riga) : null;

                return $imputazioni !== null && ! Classificazione::haGia($m, $imputazioni);
            })->values();
    }

    /**
     * Salva le modifiche di una riga: valide come le regole (verso della tipologia, area dell'attività, elementi non archiviati, voce ammessa) e la
     * riapplica. Blocca tutti i conti prima di leggere (REPEATABLE READ: una lettura semplice prima del lock fotografa dati che poi cambiano) e rilegge
     * il permesso e la chiusura della tabella a riga bloccata.
     *
     * @param  array{tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int, tag_id: ?int}  $dati
     * @return array{0: ConversioneCausale, 1: int} la riga e quanti movimenti hanno cambiato proposta
     *
     * @throws ValidationException
     */
    public static function salva(User $utente, ConversioneCausale $riga, array $dati): array
    {
        return self::inTransazione($utente, function () use ($utente, $riga, $dati) {
            $riga = ConversioneCausale::lockForUpdate()->findOrFail($riga->id);
            if ($riga->verso === 'giroconto') {
                throw ValidationException::withMessages(['tabella' => 'Prelievi e versamenti sono giroconti: non hanno una classificazione.']);
            }
            self::controlla($riga, $dati);

            $prima = $riga->istantanea();
            $riga->forceFill($dati)->save();
            RegistroModifica::registra($utente, $riga, 'modificata', $prima, $riga->istantanea());

            return [$riga, self::riapplica($utente, $riga->refresh())];
        });
    }

    /**
     * Una causale che la tabella non aveva (un movimento arrivato come dubbio): si aggiunge con la sua classificazione e si riapplica ai movimenti che la aspettano.
     *
     * @param  array{tipologia_id: ?int, area: ?string, attivita_id: ?int, iniziativa_id: ?int, tag_id: ?int}  $dati
     * @return array{0: ConversioneCausale, 1: int}
     *
     * @throws ValidationException
     */
    public static function aggiungi(User $utente, string $causale, string $verso, array $dati): array
    {
        return self::inTransazione($utente, function () use ($utente, $causale, $verso, $dati) {
            $etichetta = trim(preg_replace('/^\s*(->|→)\s*/u', '', $causale));
            if ($etichetta === '' || ! in_array($verso, ['entrata', 'uscita'], true)) {
                throw ValidationException::withMessages(['causale' => 'Scrivi la causale com’è nel foglio e scegli se è un’entrata o un’uscita.']);
            }
            if (ConversioneCausale::where('chiave', EsempiDalFoglio::chiave($etichetta))->exists()) {
                throw ValidationException::withMessages(['causale' => "La causale «$etichetta» è già nella tabella."]);
            }
            $riga = new ConversioneCausale(['causale' => $etichetta, 'chiave' => EsempiDalFoglio::chiave($etichetta), 'verso' => $verso]);
            self::controlla($riga, $dati);

            $riga->forceFill($dati)->save();
            RegistroModifica::registra($utente, $riga, 'creata', null, $riga->istantanea());

            return [$riga, self::riapplica($utente, $riga->refresh())];
        });
    }

    /** Il lock di tutti i conti prima di qualunque lettura, poi il permesso riletto e la tabella ancora aperta. */
    private static function inTransazione(User $utente, callable $lavoro): array
    {
        return DB::transaction(function () use ($utente, $lavoro) {
            Conto::orderBy('id')->lockForUpdate()->get(['id']);
            abort_unless(User::find($utente->id)?->can('gestire_regole'), 403);
            if (self::chiusa()) {
                throw ValidationException::withMessages(['tabella' => 'La data di passaggio è passata: la tabella di conversione è chiusa insieme all’import.']);
            }

            return $lavoro();
        });
    }

    /** @throws ValidationException */
    private static function controlla(ConversioneCausale $riga, array $dati): void
    {
        $errori = [];
        $tipologia = $dati['tipologia_id'] ? Tipologia::find($dati['tipologia_id']) : null;
        $iniziativa = $dati['iniziativa_id'] ? Iniziativa::find($dati['iniziativa_id']) : null;
        $attivita = $dati['attivita_id'] ? Attivita::find($dati['attivita_id']) : null;
        $tag = $dati['tag_id'] ? Tag::find($dati['tag_id']) : null;
        $area = $dati['area'] ?? $attivita?->area->value;

        if ($tipologia && $tipologia->verso !== $riga->verso) {
            $errori['tipologia_id'] = "«{$tipologia->nome}» è una tipologia di ".Tipologia::VERSI[$tipologia->verso].', ma la causale è un’'.ConversioneCausale::VERSI[$riga->verso].'.';
        }
        if ($tipologia && $area === null && $iniziativa === null) {
            $errori['area'] = 'Scegli l’area, o l’iniziativa che la porta.';
        }
        if ($tipologia && $area !== null && ! DB::table('voci_modello_d')->where(['esercizio' => now()->year, 'area' => $area, 'tipologia_id' => $tipologia->id])->exists()) {
            $errori['tipologia_id'] ??= "La tipologia «{$tipologia->nome}» non è ammessa nell’area $area per il ".now()->year.'.';
        }
        if ($attivita && $area !== $attivita->area->value) {
            $errori['attivita_id'] = "L’attività «{$attivita->nome}» non è dell’area $area.";
        }
        // Un elemento archiviato non si sceglie più, ma la riga che lo ha già può tenerlo.
        foreach (['tipologia_id' => $tipologia, 'iniziativa_id' => $iniziativa, 'attivita_id' => $attivita, 'tag_id' => $tag] as $campo => $elemento) {
            if ($elemento?->archiviata() && $elemento->id !== $riga->$campo) {
                $errori[$campo] = "«{$elemento->nome}» è archiviata: non si sceglie più.";
            }
        }
        if ($errori !== []) {
            throw ValidationException::withMessages($errori);
        }
    }

    /** @return Collection<string, ConversioneCausale> per chiave della causale (minuscola, senza prefisso) */
    private static function tabella(): Collection
    {
        return ConversioneCausale::with('tipologia', 'iniziativa', 'attivita', 'tag')->get()->keyBy(fn (ConversioneCausale $c) => EsempiDalFoglio::chiave($c->causale));
    }

    /**
     * Propone la classificazione della riga, o spiega con un dubbio perché non si può. Il movimento è bloccato da chi chiama.
     *
     * @return bool se il movimento ha cambiato proposta
     */
    private static function assegna(User $utente, Movimento $movimento, Collection $tabella): bool
    {
        $causale = $movimento->causale === null ? null : EsempiDalFoglio::chiave($movimento->causale);
        $riga = $causale ? $tabella->get($causale) : null;
        // Prima i giroconti (cascata di #33): prelievi e versamenti li abbina l'operatore col c/c, nessuna tabella li classifica.
        if (Giroconti::regolaDi($movimento) !== null) {
            Note::segnalaDubbio($utente, $movimento, self::DUBBIO_GIROCONTO.'prelievo o versamento da abbinare al movimento del c/c. Finché non è abbinato è in dubbio e fuori dal rendiconto.', self::DUBBIO_GIROCONTO);

            return false;
        }
        $imputazioni = $riga ? Classificazione::imputazioniDellaConversione($movimento, $riga) : null;
        $eraProposto = $movimento->conversione_causale_id !== null;

        if ($imputazioni !== null) {
            if ($eraProposto && $movimento->conversione_causale_id === $riga->id && Classificazione::haGia($movimento, $imputazioni)) {
                $cambiato = false;
            } else {
                Classificazione::proponi($utente, $movimento, null, null, $imputazioni, null, $riga);
                $cambiato = true;
            }
            // La riga ora risolve quello che il dubbio diceva: si chiude, ma solo quello della tabella.
            $aperti = $movimento->note()->dubbioAperto()->where('testo', 'like', self::DUBBIO_TABELLA.'%')->pluck('id')->all();
            if ($aperti !== []) {
                Note::chiudiDubbi($utente, $movimento, $aperti);
            }

            return $cambiato;
        }

        $motivo = match (true) {
            $causale === null || $causale === '' => 'il foglio non dice la causale: classificalo a mano.',
            $riga === null => "la causale «{$movimento->causale}» non è nella tabella: aggiungila nella pagina della tabella o classifica a mano.",
            $riga->tipologia_id === null => "la causale «{$riga->causale}» non ha una tipologia: scegline una nella tabella o classifica a mano.",
            default => "la classificazione della causale «{$riga->causale}» non vale per questo movimento (segno, voce o elemento archiviato): classificalo a mano.",
        };
        $cambiato = false;
        if ($eraProposto) { // la riga non propone più: la proposta di prima si toglie
            Classificazione::proponi($utente, $movimento, null, null, []);
            $cambiato = true;
        }
        Note::segnalaDubbio($utente, $movimento, self::DUBBIO_TABELLA.$motivo, self::DUBBIO_TABELLA);

        return $cambiato;
    }
}
