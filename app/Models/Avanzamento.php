<?php

namespace App\Models;

use App\Models\Concerns\NelDatabaseVero;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Dove è arrivato un utente in una missione. Niente errori, tempi o tentativi: l'Amministratore vede solo le completate (#21). */
#[Fillable(['user_id', 'missione', 'stato', 'passo', 'versione', 'versione_completata', 'novita_vista', 'invitato_il', 'completata_il'])]
class Avanzamento extends Model
{
    use NelDatabaseVero;

    protected $table = 'onboarding_avanzamenti';

    protected function casts(): array
    {
        return ['invitato_il' => 'datetime', 'completata_il' => 'datetime'];
    }

    public function completata(): bool
    {
        return $this->versione_completata !== null;
    }
}
