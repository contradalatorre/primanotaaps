<?php

namespace App\Esercizio;

use App\Models\DatiEsercizio;
use App\Models\Iniziativa;
use App\Models\LiberalitaNonMonetaria;
use App\Models\RaccoltaOccasionale;
use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Le raccolte fondi occasionali di un esercizio (#61, #18): porta dal database a `Raccolta` (che non lo conosce) le imputazioni con voce C2, una raccolta per
 * iniziativa, la tabella tipologia → riga dello schema e i dati scritti a mano. Le imputazioni sono quelle di `Prospetto::imputazioni`: la voce C2 del Modello D e le raccolte
 * partono dalle stesse righe, quindi la quadratura dice solo ciò che davvero non torna. Le scritture passano dal blocco della riga dei dati dell'esercizio.
 */
final class Raccolte
{
    /**
     * Le raccolte dell'esercizio: ogni iniziativa con imputazioni C2, o con i dati della raccolta già scritti, o che nell'esercizio ha come predefinito l'area C e un'attività occasionale.
     * Quel che si salva con l'approvazione (`Verifiche::istantanea`) e il PDF di deposito stampa.
     *
     * @param  ?Collection<int, array>  $imputazioni  quelle di `Prospetto::imputazioni`, se chi chiama le ha già lette
     * @return array{raccolte: list<array>, quadratura: array, bloccanti: list<string>, senzaRiga: int}
     */
    public static function calcola(int $anno, bool $proposte = false, ?Collection $imputazioni = null): array
    {
        $imputazioni ??= Prospetto::imputazioni($anno, $proposte);
        $c2 = $imputazioni->filter(fn (array $r) => $r['imputazione']->area->value === 'C' && $r['voce'] === 'C2');
        $righeDello = DB::table('righe_raccolta')->where('esercizio', $anno)->pluck('riga', 'tipologia_id');

        $somma = fn (Collection $righe, string $lato) => $righe->where('lato', $lato)->sum(fn (array $r) => abs($r['imputazione']->importo));
        $senzaIniziativa = $c2->whereNull('imputazione.iniziativa_id');
        $totaleC2 = ['entrate' => $somma($c2, 'entrate'), 'uscite' => $somma($c2, 'uscite')];

        $dati = RaccoltaOccasionale::with('liberalita', 'attivitaDestinazione')->where('esercizio', $anno)->get()->keyBy('iniziativa_id');
        $predefinite = DB::table('iniziativa_esercizio')->join('attivita', 'attivita.id', '=', 'iniziativa_esercizio.attivita_id')
            ->where('iniziativa_esercizio.esercizio', $anno)->where('iniziativa_esercizio.area', 'C')->where('attivita.raccolta', 'occasionale')->pluck('iniziativa_id');
        $ids = collect([...$c2->pluck('imputazione.iniziativa_id')->filter()->all(), ...$dati->keys()->all(), ...$predefinite->all()])->unique();
        $nomi = Iniziativa::whereIn('id', $ids)->orderBy('nome')->pluck('nome', 'id');

        $raccolte = [];
        foreach ($nomi as $id => $nome) {
            $record = $dati->get($id);
            $righe = $c2->where('imputazione.iniziativa_id', $id)->map(fn (array $r) => [
                'lato' => $r['lato'], 'riga' => $r['imputazione']->tipologia_id === null ? null : $righeDello->get($r['imputazione']->tipologia_id),
                'importo' => abs($r['imputazione']->importo), 'conto' => $r['imputazione']->movimento->conto->riga_modello_d,
            ])->values()->all();
            $raccolte[] = ['iniziativa_id' => $id, 'nome' => $nome, 'raccolta_id' => $record?->id]
                + Raccolta::calcola($righe, $record?->liberalita->map->only(['id', 'descrizione', 'quantita', 'valore', 'criterio', 'documento'])->all() ?? [], self::dati($record));
        }

        $quadratura = Raccolta::quadratura($raccolte, $totaleC2, ['entrate' => $somma($senzaIniziativa, 'entrate'), 'uscite' => $somma($senzaIniziativa, 'uscite')]);

        return [
            'raccolte' => $raccolte, 'quadratura' => $quadratura, 'bloccanti' => Raccolta::bloccanti($raccolte, $quadratura),
            // Le imputazioni in area C che nessuna raccolta può contare: senza riga dello schema, o in C2 senza iniziativa.
            'senzaRiga' => array_sum(array_map(fn (array $r) => $r['senzaRiga']['entrate']['numero'] + $r['senzaRiga']['uscite']['numero'], $raccolte)) + $senzaIniziativa->count(),
        ];
    }

    /** Ciò che il PDF e le pagine mostrano di un esercizio: l'istantanea del deposito se c'è, altrimenti il calcolo di adesso. */
    public static function vista(int $anno, bool $proposte = false): array
    {
        return Deposito::salvata($anno)['prospetti']['raccolte'] ?? self::calcola($anno, $proposte);
    }

    /** @return array<string, mixed> i dati a mano come li vuole `Raccolta::calcola` */
    private static function dati(?RaccoltaOccasionale $r): array
    {
        if ($r === null) {
            return [];
        }

        // Le date come testo «Y-m-d»: finiscono nell'istantanea, che è JSON.
        return ['dal' => $r->dal?->toDateString(), 'al' => $r->al?->toDateString()] + $r->only(RaccoltaOccasionale::CAMPI) + ['attivita_destinazione' => $r->attivitaDestinazione?->nome];
    }

    /**
     * Salva i dati a mano di una raccolta (la crea se manca). Dopo il deposito niente si scrive; dopo l'approvazione sì, con l'avviso della pagina (le raccolte approvate
     * restano quelle della versione approvata).
     *
     * @param  array<string, mixed>  $campi  i campi di `RaccoltaOccasionale::CAMPI`, già convalidati
     */
    public static function salva(User $utente, int $anno, int $iniziativa, array $campi): RaccoltaOccasionale
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $iniziativa, $campi) {
            DatiEsercizio::controllaNonDepositato([$anno], 'esercizio');
            $raccolta = RaccoltaOccasionale::firstOrNew(['iniziativa_id' => $iniziativa, 'esercizio' => $anno]);
            $prima = $raccolta->exists ? $raccolta->istantanea() : null;
            $raccolta->fill($campi)->save();
            if ($prima !== $raccolta->refresh()->istantanea()) {
                RegistroModifica::registra($utente, $raccolta, $prima === null ? 'creato' : 'modificato', $prima, $raccolta->istantanea());
            }

            return $raccolta;
        });
    }

    /** @param  array{descrizione: string, quantita: int, valore: int, criterio: string, documento: string}  $campi */
    public static function aggiungiLiberalita(User $utente, int $anno, int $iniziativa, array $campi): LiberalitaNonMonetaria
    {
        return DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $iniziativa, $campi) {
            DatiEsercizio::controllaNonDepositato([$anno], 'esercizio');
            $raccolta = RaccoltaOccasionale::firstOrCreate(['iniziativa_id' => $iniziativa, 'esercizio' => $anno]);
            $riga = $raccolta->liberalita()->create($campi);
            RegistroModifica::registra($utente, $raccolta, 'liberalita_aggiunta', null, ['esercizio' => $anno, 'liberalita' => self::testo($riga)]);

            return $riga;
        });
    }

    public static function eliminaLiberalita(User $utente, int $anno, int $iniziativa, int $riga): void
    {
        DatiEsercizio::sottoBlocco($utente, $anno, function () use ($utente, $anno, $iniziativa, $riga) {
            DatiEsercizio::controllaNonDepositato([$anno], 'esercizio');
            $raccolta = RaccoltaOccasionale::where('iniziativa_id', $iniziativa)->where('esercizio', $anno)->first();
            $liberalita = $raccolta?->liberalita()->whereKey($riga)->first();
            if ($liberalita === null) {
                throw ValidationException::withMessages(['liberalita' => 'Quella liberalità non c’è più.']);
            }
            $testo = self::testo($liberalita);
            $liberalita->delete();
            RegistroModifica::registra($utente, $raccolta, 'liberalita_tolta', ['esercizio' => $anno, 'liberalita' => $testo], null);
        });
    }

    /** La liberalità in una riga di testo per il registro. */
    private static function testo(LiberalitaNonMonetaria $l): string
    {
        return $l->descrizione.' · '.$l->quantita.' · '.Euro::formatta($l->valore).' € · '.$l->criterio.' · '.$l->documento;
    }
}
