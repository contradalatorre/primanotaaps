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
        // «Perdita» di ogni assegnazione (scaduta o revocata): lo spazio nato con un'assegnazione che ora non c'è più.
        // Un candidato che si allena senza ruolo (`con_assegnazione` falso) resta finché usa lo spazio.
        $senzaAssegnazione = User::whereDoesntHave('assegnazioniAttive')->pluck('id');
        $spazi = SpazioProva::where(fn ($q) => $q->where('con_assegnazione', true)->whereIn('user_id', $senzaAssegnazione))
            ->orWhere('ultimo_uso', '<', now()->subMonths(6))->pluck('user_id');
        foreach ($spazi as $id) {
            $prova->elimina($id);
        }
        $this->info("Spazi di prova cancellati: {$spazi->count()}");

        return self::SUCCESS;
    }
}
