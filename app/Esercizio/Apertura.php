<?php

namespace App\Esercizio;

use App\Models\RegistroModifica;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprire un esercizio nuovo (2027…): la tabella delle voci Modello D e le aree e attività predefinite delle iniziative esistono
 * solo per l'esercizio per cui sono state caricate (migrazione di #39), e senza una voce nessun movimento si classifica.
 * L'apertura le copia dall'ultimo esercizio precedente che le ha; poi si cambiano dalla pagina della tabella delle voci (#62).
 */
final class Apertura
{
    /** L'esercizio da cui si può copiare, o null se `$anno` ha già le sue voci o nessun esercizio precedente ne ha. */
    public static function sorgente(int $anno): ?int
    {
        if (DB::table('voci_modello_d')->where('esercizio', $anno)->exists()) {
            return null;
        }

        return DB::table('voci_modello_d')->where('esercizio', '<', $anno)->max('esercizio');
    }

    public static function apri(User $utente, int $anno): void
    {
        DB::transaction(function () use ($utente, $anno) {
            $da = self::sorgente($anno);
            if ($da === null) {
                throw ValidationException::withMessages(['esercizio' => "L'esercizio $anno ha già le sue voci, oppure non c'è un esercizio precedente da cui copiarle."]);
            }

            $voci = DB::table('voci_modello_d')->where('esercizio', $da)->get(['area', 'tipologia_id', 'voce'])
                ->map(fn ($v) => ['esercizio' => $anno] + (array) $v)->all();
            DB::table('voci_modello_d')->insert($voci);
            // Un predefinito già scelto per il nuovo esercizio non si tocca.
            $predefiniti = DB::table('iniziativa_esercizio')->where('esercizio', $da)
                ->whereNotIn('iniziativa_id', DB::table('iniziativa_esercizio')->where('esercizio', $anno)->select('iniziativa_id'))
                ->get(['iniziativa_id', 'area', 'attivita_id'])->map(fn ($p) => ['esercizio' => $anno] + (array) $p)->all();
            DB::table('iniziativa_esercizio')->insert($predefiniti);

            RegistroModifica::create([
                'user_id' => $utente->id, 'oggetto_tipo' => 'esercizio', 'oggetto_id' => $anno, 'azione' => 'aperto', 'prima' => null,
                'dopo' => ['voci_copiate' => count($voci), 'predefiniti_copiati' => count($predefiniti), 'copiati_da' => $da],
            ]);
        });
    }
}
