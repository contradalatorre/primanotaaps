<?php

namespace App\Esercizio;

use App\Euro;
use App\Models\Iniziativa;
use App\Models\PreventivoIniziativa;
use App\Models\RegistroModifica;
use App\Models\User;
use App\Permesso;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Il preventivo di un'iniziativa per un esercizio (#57): righe con tipologia e importo, il verso è quello della tipologia.
 * Facoltativo e senza approvazione: chi ha `gestire_regole` (#16) lo riscrive per intero, e nel registro resta la versione di prima e di dopo.
 */
final class Preventivo
{
    /** @return Collection<int, PreventivoIniziativa> le righe con la tipologia, in ordine di nome */
    public static function righe(int $iniziativaId, int $anno): Collection
    {
        return PreventivoIniziativa::with('tipologia')->where('iniziativa_id', $iniziativaId)->where('esercizio', $anno)->get()
            ->sortBy(fn (PreventivoIniziativa $r) => $r->tipologia->nome)->values();
    }

    /**
     * Sostituisce le righe del preventivo dell'esercizio (nessuna riga: il preventivo non c'è più). Prima il blocco sull'iniziativa,
     * poi il permesso si rilegge da zero (una revoca arrivata nel frattempo vale) e solo dopo si leggono le righe di prima.
     *
     * @param  list<array{tipologia_id: int, importo: int}>  $righe  importi in centesimi, maggiori di zero, una tipologia una sola volta
     */
    public static function salva(User $utente, int $iniziativaId, int $anno, array $righe): void
    {
        DB::transaction(function () use ($utente, $iniziativaId, $anno, $righe) {
            $iniziativa = Iniziativa::lockForUpdate()->findOrFail($iniziativaId);
            $utente->unsetRelation('assegnazioniAttive');
            abort_unless($utente->puo(Permesso::GestireRegole), 403);

            $prima = self::testo(self::righe($iniziativa->id, $anno));
            PreventivoIniziativa::where('iniziativa_id', $iniziativa->id)->where('esercizio', $anno)->delete();
            PreventivoIniziativa::insert(array_map(fn (array $r) => ['iniziativa_id' => $iniziativa->id, 'esercizio' => $anno, 'tipologia_id' => $r['tipologia_id'], 'importo' => $r['importo']], $righe));
            $dopo = self::testo(self::righe($iniziativa->id, $anno));

            if ($prima !== $dopo) { // salvare senza cambiare nulla non è una modifica
                RegistroModifica::registra($utente, $iniziativa, 'preventivo', $prima === '' ? null : [$anno => $prima], $dopo === '' ? null : [$anno => $dopo]);
            }
        });
    }

    /** Il preventivo in una riga di testo per il registro: «Acquisto di servizi 400,00 €; Somministrazione 1.200,50 €». */
    private static function testo(Collection $righe): string
    {
        return $righe->map(fn (PreventivoIniziativa $r) => $r->tipologia->nome.' '.Euro::formatta($r->importo).' €')->join('; ');
    }
}
