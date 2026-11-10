<?php

namespace App\Console\Commands;

use App\Models\SpazioProva;
use App\Models\User;
use App\Onboarding\Prova;
use Illuminate\Console\Command;

/** Cancella gli spazi di prova di chi non ha più assegnazioni attive o non li usa da 6 mesi (#71, ADR 0003); i timbri restano. */
class ProvaCancella extends Command
{
    protected $signature = 'prova:cancella';

    protected $description = 'Cancella gli spazi di prova senza assegnazioni o inattivi da 6 mesi';

    public function handle(Prova $prova): int
    {
        // «Perdita» di ogni assegnazione: ne ha avute e nessuna è più attiva. Chi non ne ha mai avute (un candidato che si allena) resta finché usa lo spazio.
        $persa = User::whereHas('assegnazioni')->whereDoesntHave('assegnazioniAttive')->pluck('id');
        $spazi = SpazioProva::whereIn('user_id', $persa)->orWhere('ultimo_uso', '<', now()->subMonths(6))->pluck('user_id');
        foreach ($spazi as $id) {
            $prova->elimina($id);
        }
        $this->info("Spazi di prova cancellati: {$spazi->count()}");

        return self::SUCCESS;
    }
}
