<?php

namespace App\Esercizio;

use App\Area;
use App\Models\Attivita;
use App\Models\Conto;
use App\Models\DatiEsercizio;
use App\Models\Imputazione;
use App\Models\Iniziativa;
use App\Models\IniziativaEsercizio;
use App\Models\Movimento;
use App\Models\RegistroModifica;
use App\Models\Tipologia;
use App\Models\User;
use App\Permesso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La riclassificazione in blocco (#62, #12, #15): il Tesoriere cambia in un colpo l'area di un'iniziativa in un esercizio, le proprietà fiscali di una tipologia o una riga
 * della tabella delle voci, e prima vede l'effetto su Modello D ed esiti dei test. Tre cambi (`tipo`): `iniziativa`, `tipologia`, `voce`.
 *
 * L'anteprima e l'applicazione sono lo stesso codice: il cambio si fa davvero in una transazione, si confronta il prospetto di prima con quello di dopo (`Differenze`) e,
 * in anteprima, si annulla. L'applicazione riceve l'impronta dell'anteprima vista e si rifiuta se l'effetto di adesso è un altro (un movimento arrivato nel frattempo).
 *
 * Un esercizio depositato non si tocca (rifiuto); uno approvato sì, con l'avviso. Nell'iniziativa i movimenti che seguivano l'area predefinita (stessa area e stessa attività) la
 * seguono; quelli con un'altra area o attività sono stati cambiati a mano e restano come sono, elencati. Le righe della tabella che mancano per la nuova area bloccano il cambio:
 * una combinazione senza riga non è ammessa. Le proprietà di una tipologia valgono per tutti gli esercizi (la colonna è una sola): quelli depositati leggono la loro istantanea e
 * non cambiano.
 * shortcut: le proprietà cambiate si vedono dal vivo anche sui movimenti di un esercizio depositato (natura sulla pagina del movimento, forzature da rivedere), mentre i prospetti
 * restano quelli depositati; versionare le proprietà per esercizio se serve. Il confronto di prima e dopo legge sotto l'istantanea di MySQL presa al controllo del permesso: una
 * scrittura confermata tra il controllo e i blocchi non entra nell'anteprima (l'impronta le rende uguali a quelle dell'applicazione, e la scrittura sulle imputazioni è letta con blocco).
 */
final class Riclassificazione
{
    /**
     * @param  array<string, mixed>  $cambio  vedi `esegui`
     * @return array{descrizione: string, aggiornate: int, a_mano: list<array>, anni: array<int, array{stato: string, differenze: array}>, depositati: list<int>, impronta: string}
     */
    public static function anteprima(User $utente, array $cambio): array
    {
        try {
            self::esegui($utente, $cambio, null);
        } catch (RiclassificazioneAnteprima $anteprima) {
            return $anteprima->esito;
        }
        throw new \LogicException('L’anteprima deve annullare la transazione.');
    }

    /** @param  string  $impronta  quella dell'anteprima vista (`anteprima`) */
    public static function applica(User $utente, array $cambio, string $impronta): array
    {
        return self::esegui($utente, $cambio, $impronta);
    }

    /**
     * Si bloccano i dati degli esercizi (in ordine di anno), poi i conti, poi i movimenti di ogni anno, come `Deposito::deposita`; le righe che si leggono per decidere si
     * leggono con blocco (MySQL, REPEATABLE READ: una lettura semplice vedrebbe la fotografia di prima dei blocchi).
     *
     * @param  array{tipo: string, iniziativa_id?: int, esercizio?: int, area?: string, attivita_id?: ?int, tipologia_id?: int, proprieta?: list<string>, voce?: ?string}  $cambio
     * @param  ?string  $impronta  null: anteprima
     */
    private static function esegui(User $utente, array $cambio, ?string $impronta): array
    {
        $anni = self::anniDa($cambio);

        return DatiEsercizio::sottoBlocchi($utente, $anni, function (array $dati) use ($utente, $cambio, $impronta, $anni) {
            Conto::orderBy('id')->lockForUpdate()->get(['id']);
            $movimenti = [];
            foreach ($anni as $anno) {
                $movimenti[$anno] = Movimento::whereBetween('data', ["$anno-01-01", "$anno-12-31"])->orderBy('id')->lockForUpdate()->pluck('id');
            }
            // Le proprietà di una tipologia valgono per tutti gli esercizi: i depositati restano com'erano (istantanea) e non si confrontano; per gli altri cambi un depositato è un rifiuto.
            $depositati = array_values(array_filter($anni, fn (int $anno) => $dati[$anno]->stato === 'depositato'));
            if ($cambio['tipo'] !== 'tipologia') {
                DatiEsercizio::controllaNonDepositato($anni, 'esercizio');
            }
            $confrontati = array_values(array_diff($anni, $depositati));

            $prima = [];
            foreach ($confrontati as $anno) {
                $prima[$anno] = Differenze::impronta(Verifiche::istantanea($anno));
            }

            $scrittura = match ($cambio['tipo']) {
                'iniziativa' => self::iniziativa($utente, $cambio, $movimenti[$cambio['esercizio']]),
                'tipologia' => self::tipologia($utente, $cambio),
                'voce' => self::voce($utente, $cambio, $movimenti[$cambio['esercizio']]),
            };

            $anniEsito = [];
            foreach ($confrontati as $anno) {
                $anniEsito[$anno] = ['stato' => $dati[$anno]->stato ?? 'aperto', 'differenze' => Differenze::tra($prima[$anno], Differenze::impronta(Verifiche::istantanea($anno)))];
            }
            $esito = [
                'descrizione' => $scrittura['descrizione'], 'aggiornate' => count($scrittura['imputazioni']), 'a_mano' => $scrittura['a_mano'],
                'anni' => $anniEsito, 'depositati' => $depositati,
                'impronta' => sha1(json_encode([$cambio, $scrittura['imputazioni'], array_column($scrittura['a_mano'], 'movimento_id'), $anniEsito])),
            ];

            if ($impronta === null) {
                throw new RiclassificazioneAnteprima($esito);
            }
            if (! hash_equals($esito['impronta'], strtolower(trim($impronta)))) {
                self::rifiuta('impronta', 'L’effetto della riclassificazione non è più quello dell’anteprima (qualcosa è cambiato nel frattempo): guarda di nuovo l’anteprima prima di applicarla.');
            }

            return $esito;
        }, Permesso::RiclassificareInBlocco);
    }

    /**
     * Gli esercizi da bloccare: quello del cambio; per una tipologia tutti quelli in cui ha imputazioni.
     * shortcut: per una tipologia l'elenco si legge prima dei blocchi; un movimento che la usa per la prima volta in un altro anno, in quel momento, non entra nell'anteprima. Rileggerlo sotto blocco se capita.
     */
    private static function anniDa(array $cambio): array
    {
        if ($cambio['tipo'] !== 'tipologia') {
            return [$cambio['esercizio']];
        }

        return DB::table('imputazioni')->join('movimenti', 'movimenti.id', '=', 'imputazioni.movimento_id')->whereNull('movimenti.eliminato_il')
            ->where('imputazioni.tipologia_id', $cambio['tipologia_id'])->selectRaw('distinct year(movimenti.data) as anno')->orderBy('anno')->pluck('anno')
            ->map(fn ($anno) => (int) $anno)->all();
    }

    /**
     * L'area (e l'attività) predefinita dell'iniziativa nell'esercizio: i movimenti che la seguivano la seguono, gli altri si elencano.
     *
     * @param  Collection<int, int>  $movimenti  gli id dei movimenti dell'esercizio, già bloccati
     */
    private static function iniziativa(User $utente, array $cambio, Collection $movimenti): array
    {
        $anno = $cambio['esercizio'];
        $iniziativa = Iniziativa::findOrFail($cambio['iniziativa_id']);
        $area = Area::from($cambio['area']);
        $attivitaId = $cambio['attivita_id'] ?? null;
        $predefinito = IniziativaEsercizio::where('iniziativa_id', $iniziativa->id)->where('esercizio', $anno)->lockForUpdate()->first();
        if ($predefinito === null) {
            self::rifiuta('area', "«{$iniziativa->nome}» non ha un'area predefinita per il $anno: la si imposta dalla pagina Classificazione, la riclassificazione cambia quella che c'è.");
        }
        if ($predefinito->area === $area && $predefinito->attivita_id === $attivitaId) {
            self::rifiuta('area', "«{$iniziativa->nome}» ha già questa area e questa attività nel $anno: non c'è niente da cambiare.");
        }
        if ($attivitaId !== null) {
            $attivita = Attivita::findOrFail($attivitaId);
            if ($attivita->area !== $area) {
                self::rifiuta('attivita_id', "L'attività «{$attivita->nome}» non è dell'area {$area->value}.");
            }
            if ($attivita->archiviata() && $attivitaId !== $predefinito->attivita_id) {
                self::rifiuta('attivita_id', "L'attività «{$attivita->nome}» è archiviata: non si sceglie più.");
            }
        }

        // Senza la commissione di un incasso (importo negativo, area D, nessuna iniziativa): non segue l'iniziativa.
        $imputazioni = Imputazione::whereIn('movimento_id', $movimenti)->where('iniziativa_id', $iniziativa->id)->where('importo', '>', 0)->orderBy('id')->lockForUpdate()->get();
        [$seguono, $aMano] = $imputazioni->partition(fn (Imputazione $i) => $i->area === $predefinito->area && $i->attivita_id === $predefinito->attivita_id);

        $tipologie = $seguono->pluck('tipologia_id')->filter()->unique();
        $conRiga = DB::table('voci_modello_d')->where('esercizio', $anno)->where('area', $area->value)->whereIn('tipologia_id', $tipologie)->pluck('tipologia_id');
        $mancano = Tipologia::whereIn('id', $tipologie->diff($conRiga))->orderBy('nome')->pluck('nome');
        if ($mancano->isNotEmpty()) {
            self::rifiuta('area', "Per l'area {$area->value} nel $anno la tabella delle voci non ha una riga per: ".$mancano->join(', ').'. Senza riga i movimenti non avrebbero una voce del Modello D: aggiungi le righe (qui sotto, «Tabella delle voci») e riprova.');
        }

        $daCambiare = Movimento::whereIn('id', $seguono->pluck('movimento_id')->unique())->orderBy('id')->get();
        // shortcut: due letture complete (prima e dopo) per movimento cambiato, per il registro; da ripensare solo se un'iniziativa ha migliaia di movimenti in un esercizio.
        $prima = $daCambiare->mapWithKeys(fn (Movimento $m) => [$m->id => $m->istantanea()]);
        $primaIniziativa = $iniziativa->istantanea();
        $areaPrima = $predefinito->area->value;

        Imputazione::whereIn('id', $seguono->pluck('id'))->update(['area' => $area->value, 'attivita_id' => $attivitaId, 'updated_at' => now()]);
        $predefinito->forceFill(['area' => $area->value, 'attivita_id' => $attivitaId])->save();

        RegistroModifica::registra($utente, $iniziativa, 'riclassificata', $primaIniziativa, $iniziativa->istantanea());
        foreach ($daCambiare as $movimento) {
            RegistroModifica::registra($utente, $movimento, 'riclassificato', $prima[$movimento->id], $movimento->istantanea());
        }

        $aMano = Movimento::whereIn('id', $aMano->pluck('movimento_id')->unique())->orderBy('data')->orderBy('id')->get()
            ->map(fn (Movimento $m) => ['movimento_id' => $m->id, 'data' => $m->data->format('d/m/Y'), 'descrizione' => $m->descrizione, 'importo' => $m->importo, 'stato' => $m->stato, 'import_id' => $m->import_estratto_id])->all();

        return [
            'descrizione' => "«{$iniziativa->nome}» passa dall'area $areaPrima all'area {$area->value} nel $anno.",
            'imputazioni' => $seguono->pluck('id')->all(), 'a_mano' => $aMano,
        ];
    }

    /** Le proprietà fiscali di una tipologia: valgono per tutti gli esercizi non depositati, senza toccare nessun movimento. */
    private static function tipologia(User $utente, array $cambio): array
    {
        $tipologia = Tipologia::lockForUpdate()->findOrFail($cambio['tipologia_id']);
        $nuove = collect($cambio['proprieta'] ?? [])->unique()->sort()->values()->all();
        if ($nuove === collect($tipologia->proprieta)->sort()->values()->all()) {
            self::rifiuta('proprieta', "«{$tipologia->nome}» ha già queste proprietà: non c'è niente da cambiare.");
        }

        $prima = $tipologia->istantanea();
        $tipologia->forceFill(['proprieta' => $nuove])->save();
        RegistroModifica::registra($utente, $tipologia, 'riclassificata', $prima, $tipologia->istantanea());

        return ['descrizione' => "Le proprietà fiscali di «{$tipologia->nome}» cambiano in: ".(collect($nuove)->map(fn ($p) => Tipologia::PROPRIETA[$p])->join(', ') ?: 'nessuna').'.', 'imputazioni' => [], 'a_mano' => []];
    }

    /**
     * Una riga della tabella delle voci (area + tipologia → voce) di un esercizio: si aggiunge, si cambia o (`voce` nulla) si toglie, e si toglie solo se nessuna imputazione dell'esercizio la usa.
     *
     * @param  Collection<int, int>  $movimenti  gli id dei movimenti dell'esercizio, già bloccati
     */
    private static function voce(User $utente, array $cambio, Collection $movimenti): array
    {
        $anno = $cambio['esercizio'];
        $tipologia = Tipologia::findOrFail($cambio['tipologia_id']);
        $area = Area::from($cambio['area']);
        $voce = $cambio['voce'] ?? null;
        $riga = DB::table('voci_modello_d')->where('esercizio', $anno)->where('area', $area->value)->where('tipologia_id', $tipologia->id)->lockForUpdate()->first();
        $chiave = ['esercizio' => $anno, 'area' => $area->value, 'tipologia_id' => $tipologia->id];
        $nome = "«{$tipologia->nome}» in area {$area->value}";

        if ($voce === null) {
            if ($riga === null) {
                self::rifiuta('voce', "Nel $anno $nome non ha una riga da togliere.");
            }
            $usi = Imputazione::whereIn('movimento_id', $movimenti)->where('area', $area->value)->where('tipologia_id', $tipologia->id)->lockForUpdate()->count();
            if ($usi > 0) {
                self::rifiuta('voce', "Nel $anno $usi ".($usi === 1 ? 'imputazione usa' : 'imputazioni usano')." $nome: la riga non si toglie finché serve. Riclassifica prima quei movimenti.");
            }
            DB::table('voci_modello_d')->where('id', $riga->id)->delete();
            $azione = 'voce_tolta';
        } else {
            $lato = $tipologia->verso === 'entrata' ? 'entrate' : 'uscite';
            // La voce è una riga del Modello D su quel lato e sta nell'area della riga («A3» in area A), tranne «Imposte» e gli investimenti («I1»).
            if (! SchemaModelloD::conosce($lato, $voce) || ! (str_starts_with($voce, $area->value) || $voce === 'Imposte' || str_starts_with($voce, 'I'))) {
                self::rifiuta('voce', "«{$voce}» non è una riga del Modello D delle $lato per l'area {$area->value}.");
            }
            if ($riga?->voce === $voce) {
                self::rifiuta('voce', "Nel $anno $nome ha già la voce $voce: non c'è niente da cambiare.");
            }
            $riga === null ? DB::table('voci_modello_d')->insert($chiave + ['voce' => $voce]) : DB::table('voci_modello_d')->where('id', $riga->id)->update(['voce' => $voce]);
            $azione = 'voce_impostata';
        }

        RegistroModifica::create([
            'user_id' => $utente->id, 'oggetto_tipo' => 'esercizio', 'oggetto_id' => $anno, 'azione' => $azione,
            'prima' => ['area' => $area->value, 'tipologia_id' => $tipologia->id, 'voce' => $riga?->voce], 'dopo' => ['area' => $area->value, 'tipologia_id' => $tipologia->id, 'voce' => $voce],
        ]);

        return ['descrizione' => "Nel $anno $nome ".($voce === null ? 'non ha più una voce del Modello D.' : "va nella voce $voce del Modello D."), 'imputazioni' => [], 'a_mano' => []];
    }

    private static function rifiuta(string $campo, string $messaggio): never
    {
        throw ValidationException::withMessages([$campo => $messaggio]);
    }
}
